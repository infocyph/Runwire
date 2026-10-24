"""Real asynchronous HTTP/1.1 promotion-client checks; no performance assertions."""

import asyncio
import json
import os
from pathlib import Path
import socket
import subprocess
import tempfile
import time
import unittest

ROOT = Path(__file__).resolve().parents[2]
import sys
sys.path.insert(0, str(ROOT / "benchmarks"))

from http1_adaptive_matrix import run_phase


def cases():
    manifest = json.loads((ROOT / "benchmarks" / "adaptive_promotion_cases.json").read_text())
    return [case for case in manifest if case["protocol"] == "http1"]


def stop_server(server):
    if server.poll() is not None:
        raise RuntimeError(f"Benchmark server exited unexpectedly ({server.returncode})")
    server.terminate()
    try:
        server.wait(timeout=10)
    except subprocess.TimeoutExpired:
        server.kill()
        server.wait()
        raise RuntimeError("Benchmark server failed graceful shutdown")


class H1ClientTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.directory = Path(self.temp.name)
        self.cert, self.key = self.directory / "cert.pem", self.directory / "key.pem"
        subprocess.run(
            [
                "openssl", "req", "-x509", "-newkey", "rsa:2048", "-nodes", "-days", "1",
                "-subj", "/CN=localhost", "-keyout", str(self.key), "-out", str(self.cert),
            ],
            check=True,
            stdout=subprocess.DEVNULL,
            stderr=subprocess.DEVNULL,
        )

    def tearDown(self):
        self.temp.cleanup()

    def exercise(self, case):
        with socket.socket() as probe:
            probe.bind(("127.0.0.1", 0))
            port = probe.getsockname()[1]

        command = [
            "php", "benchmarks/adaptive_http_server.php", "http1", str(port), "auto",
            str(case["payload_bytes"]),
        ]
        if case["tls"]:
            command.extend([str(self.cert), str(self.key)])

        with (self.directory / "server.log").open("w") as log:
            server = subprocess.Popen(
                command,
                cwd=ROOT,
                stdout=log,
                stderr=log,
                start_new_session=True,
            )
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

                old = os.environ.get("RUNWIRE_ADAPTIVE_CASE")
                os.environ["RUNWIRE_ADAPTIVE_CASE"] = json.dumps(case)
                try:
                    result = asyncio.run(run_phase(port, server.pid, 2, 0.15))
                finally:
                    if old is None:
                        os.environ.pop("RUNWIRE_ADAPTIVE_CASE", None)
                    else:
                        os.environ["RUNWIRE_ADAPTIVE_CASE"] = old

                self.assertTrue(result["correctness_passed"])
                self.assertGreater(result["requests"], 0)
                self.assertEqual(result["errors"], 0)
            finally:
                stop_server(server)

    def test_plaintext_and_tls_reuse_validate_exact_response_bytes(self):
        manifest = cases()
        selected = [
            next(case for case in manifest if not case["tls"] and case["payload_bytes"] == 2 and case["keepalive"] == 8),
            next(case for case in manifest if case["tls"] and case["payload_bytes"] == 65536 and case["keepalive"] == 800),
        ]
        for case in selected:
            with self.subTest(case=case["id"]):
                self.exercise(case)


if __name__ == "__main__":
    unittest.main()
