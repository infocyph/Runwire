"""Short native-fixture checks for the benchmark clients; no performance assertions."""

import asyncio
import json
import os
from pathlib import Path
import socket
import subprocess
import sys
import tempfile
import time
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / "benchmarks"))
from adaptive_promotion_matrix import cases, stop_server
from h2_adaptive_matrix import H2Bench
from http1_adaptive_matrix import run_phase


class NativeClientTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.directory = Path(self.temp.name)
        self.cert, self.key = self.directory / "cert.pem", self.directory / "key.pem"
        subprocess.run(["openssl", "req", "-x509", "-newkey", "rsa:2048", "-nodes", "-days", "1",
                        "-subj", "/CN=localhost", "-keyout", str(self.key), "-out", str(self.cert)],
                       check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)

    def tearDown(self):
        self.temp.cleanup()

    def exercise(self, case):
        with socket.socket() as probe:
            probe.bind(("127.0.0.1", 0))
            port = probe.getsockname()[1]
        command = ["php", "benchmarks/adaptive_http_server.php", case["protocol"], str(port), "auto", str(case["payload_bytes"])]
        if case["protocol"] == "h2" or case["tls"]:
            command.extend([str(self.cert), str(self.key)])
        with (self.directory / "server.log").open("w") as log:
            server = subprocess.Popen(command, cwd=ROOT, stdout=log, stderr=log, start_new_session=True)
            try:
                deadline = time.monotonic() + 10
                while True:
                    if server.poll() is not None or time.monotonic() >= deadline:
                        self.fail((self.directory / "server.log").read_text())
                    try:
                        with socket.create_connection(("127.0.0.1", port), timeout=0.05):
                            break
                    except OSError:
                        time.sleep(0.03)
                with patch.dict(os.environ, RUNWIRE_ADAPTIVE_CASE=json.dumps(case)):
                    if case["protocol"] == "http1":
                        result = asyncio.run(run_phase(port, server.pid, 2, 0.15))
                    else:
                        bench = H2Bench(port, server.pid, case["payload_bytes"])
                        try:
                            result = bench.run_phase(case["high"], 0.15)
                            bench.issued = 8000
                            rotated = bench.run_phase(2, 0.1)
                            self.assertEqual(rotated["observed"]["connection_rotations"], 1)
                        finally:
                            bench.close()
                    self.assertTrue(result["correctness_passed"])
                    self.assertGreater(result["requests"], 0)
            finally:
                stop_server(server)

    def test_h1_plaintext_and_tls_reuse_validate_response_bytes(self):
        for case in [cases("http1")[0], cases("http1")[-1]]:
            with self.subTest(case=case["id"]):
                self.exercise(case)

    def test_h2_mixed_flow_and_pressure_cases_keep_limits_and_rotate(self):
        for scenario in ("steady", "mixed", "flow", "pressure"):
            case = next(c for c in cases("h2") if c["scenario"] == scenario and c["high"] == 100)
            with self.subTest(case=case["id"]):
                self.exercise(case)


if __name__ == "__main__":
    unittest.main()
