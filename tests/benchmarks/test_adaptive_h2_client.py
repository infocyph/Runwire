"""Real hyper-h2 client checks; no performance assertions."""

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

from adaptive_benchmark import Latencies, phase_plan
from h2_adaptive_matrix import H2Bench


def cases(protocol):
    manifest = json.loads((ROOT / "benchmarks" / "adaptive_promotion_cases.json").read_text())
    return [case for case in manifest if case["protocol"] == protocol]


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


class H2ClientTest(unittest.TestCase):
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
            "php", "benchmarks/adaptive_http_server.php", "h2", str(port), "auto",
            str(case["payload_bytes"]), str(self.cert), str(self.key),
        ]
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
                    bench = H2Bench(port, server.pid, case["payload_bytes"])
                    try:
                        result = bench.run_phase(case["high"], 0.15)
                        bench.issued = 8000
                        rotated = bench.run_phase(2, 0.1)
                        self.assertEqual(rotated["observed"]["connection_rotations"], 1)
                    finally:
                        bench.close()
                finally:
                    if old is None:
                        os.environ.pop("RUNWIRE_ADAPTIVE_CASE", None)
                    else:
                        os.environ["RUNWIRE_ADAPTIVE_CASE"] = old

                self.assertTrue(result["correctness_passed"])
                self.assertGreater(result["requests"], 0)
            finally:
                stop_server(server)

    def test_mixed_flow_and_pressure_cases_keep_limits_and_rotate(self):
        for scenario in ("steady", "mixed", "flow", "pressure"):
            case = next(
                value for value in cases("h2")
                if value["scenario"] == scenario and value["high"] == 100
            )
            with self.subTest(case=case["id"]):
                self.exercise(case)

    def test_measurement_histogram_is_bounded_and_preserves_tail(self):
        latencies = Latencies()
        for _ in range(10000):
            latencies.append(0.151)
        latencies.append(2000)
        self.assertEqual(len(latencies.buckets), 2)
        self.assertEqual(latencies.percentile(0.99), 0.16)
        self.assertEqual(latencies.percentile(1), 2000)
        for value in (float("inf"), float("nan"), -1, 10001):
            with self.assertRaises(ValueError):
                latencies.append(value)

    def test_phase_plan_preserves_medium_and_transition_order(self):
        case = cases("h2")[0]
        old = os.environ.get("RUNWIRE_ADAPTIVE_CASE")
        os.environ["RUNWIRE_ADAPTIVE_CASE"] = json.dumps(case)
        try:
            phases = phase_plan(180, 8, 64)
        finally:
            if old is None:
                os.environ.pop("RUNWIRE_ADAPTIVE_CASE", None)
            else:
                os.environ["RUNWIRE_ADAPTIVE_CASE"] = old
        self.assertEqual(tuple(item[0] for item in phases), (
            "low_before", "medium", "transition_up", "high", "transition_down", "low_after",
        ))


if __name__ == "__main__":
    unittest.main()
