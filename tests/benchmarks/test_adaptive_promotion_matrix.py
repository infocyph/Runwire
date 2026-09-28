"""Contract tests; synthetic evidence tests the gate, never certifies runtime performance."""

import copy
import json
import os
from pathlib import Path
import sys
import tempfile
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / "benchmarks"))
from adaptive_benchmark import Latencies, phase_plan
from adaptive_promotion_matrix import cases, evaluate, evaluate_protocol, validate_record, MODES, PHASES


class PromotionGateTest(unittest.TestCase):
    def valid_results(self, protocol):
        comparison = dict(passed=True, measurement_qualified=True, phases={
            name: dict(throughput_ratio=1.06, combined_rps_cv_percent=0.1, p95_absolute_delta_ms=0, p99_absolute_delta_ms=0)
            for name in PHASES})
        return {case["id"]: dict(valid=True, comparisons={mode: copy.deepcopy(comparison) for mode in MODES[:-1]})
                for case in cases(protocol)}

    def test_manifest_covers_required_workloads(self):
        h1, h2, h3 = (cases(protocol) for protocol in ("http1", "h2", "h3"))
        self.assertEqual((len(h1), len(h2), len(h3)), (16, 28, 42))
        self.assertEqual({c["tls"] for c in h1}, {False, True})
        self.assertEqual({c["keepalive"] for c in h1}, {8, 800})
        self.assertEqual({c["high"] for c in h2}, {1, 8, 32, 100})
        self.assertEqual({c["scenario"] for c in h2}, {"steady", "mixed", "flow", "pressure"})
        self.assertEqual({c["scenario"] for c in h3}, {"inbound", "outbound", "balanced", "qpack", "churn"})
        for manifest in (h1, h2, h3):
            self.assertEqual(len(manifest), len({c["id"] for c in manifest}))
            self.assertEqual({c["payload_bytes"] for c in manifest}, {2, 1024, 16384, 65536})

    def test_complete_performance_evidence_does_not_automatically_certify_release(self):
        for protocol in ("http1", "h2", "h3"):
            result = evaluate_protocol(self.valid_results(protocol), protocol)
            self.assertTrue(result["performance_gate_passed"])
            self.assertFalse(result["promotion_certified"])

    def test_missing_cells_profiles_or_qualification_cannot_pass(self):
        for mutation in ("missing", "profile", "variance", "regression"):
            evidence = self.valid_results("h2")
            key = next(iter(evidence))
            if mutation == "missing":
                del evidence[key]
            elif mutation == "profile":
                del evidence[key]["comparisons"]["throughput"]
            else:
                evidence[key]["comparisons"]["fixed"]["measurement_qualified" if mutation == "variance" else "passed"] = False
            self.assertFalse(evaluate_protocol(evidence, "h2")["performance_gate_passed"])

    def test_gain_does_not_hide_transition_or_other_workload_regression(self):
        evidence = self.valid_results("h2")
        first = next(iter(evidence.values()))
        first["comparisons"]["fixed"]["phases"]["transition_up"]["throughput_ratio"] = 0.98
        self.assertFalse(evaluate_protocol(evidence, "h2")["performance_gate_passed"])
        evidence = self.valid_results("h2")
        for result in evidence.values():
            for phase in result["comparisons"]["fixed"]["phases"].values():
                phase["throughput_ratio"] = 1.01
        self.assertFalse(evaluate_protocol(evidence, "h2")["performance_gate_passed"])

    def test_apparent_gain_within_combined_noise_cannot_qualify(self):
        evidence = self.valid_results("h2")
        for result in evidence.values():
            for phase in result["comparisons"]["fixed"]["phases"].values():
                phase["combined_rps_cv_percent"] = 3.5
        self.assertFalse(evaluate_protocol(evidence, "h2")["performance_gate_passed"])

    def test_declared_scenarios_require_observed_work(self):
        case = next(c for c in cases("h3") if c["scenario"] == "qpack")
        phases = {name: dict(concurrency=case["medium"] if name == "medium" else case["high"] if name in {"high", "transition_up"} else case["low"],
                            correctness_passed=True, client_cpu_percent=10, observed={"qpack_delayed_headers": 1}) for name in PHASES}
        identity = dict(build="synthetic", environment_id="test")
        record = dict(case=case, mode="auto", trial=1, workload=case["id"], protocol="h3", payload_bytes=case["payload_bytes"],
                      runtime_build="synthetic", environment="test", run_id="fixture", correctness_passed=True, phases=phases)
        validate_record(record, case, "auto", 1, identity)
        record["phases"]["high"]["observed"]["qpack_delayed_headers"] = 0
        with self.assertRaises(ValueError):
            validate_record(record, case, "auto", 1, identity)
        with self.assertRaises(ValueError):
            validate_record(record, case, "fixed", 1, identity)

    def test_raw_artifact_evaluation_recomputes_profiles_and_rejects_failed_trials(self):
        case = cases("h2")[0]
        identity = dict(build="synthetic", environment_id="test")
        with tempfile.TemporaryDirectory() as name:
            output = Path(name)
            directory = output / case["id"]
            directory.mkdir()
            for mode in MODES:
                for trial in range(1, 6):
                    phases = {}
                    for phase in PHASES:
                        phases[phase] = dict(requests=18000, errors=0, duration_seconds=180.0,
                            requested_duration_seconds=180.0, concurrency=1,
                            throughput_rps=106.0 if mode == "auto" else 100.0,
                            p50_ms=1.0, p95_ms=2.0, p99_ms=3.0, cpu_percent=10.0,
                            client_cpu_percent=10.0, rss_peak_bytes=1048576,
                            fairness_ratio=1.0, correctness_passed=True, observed={})
                    record = dict(case=case, trial=trial, mode=mode, protocol="h2", workload=case["id"],
                                  payload_bytes=case["payload_bytes"], runtime_build="synthetic", environment="test",
                                  run_id=f"fixture-{mode}-{trial}", warmup_seconds=30.0, phases=phases, correctness_passed=True)
                    (directory / f"{mode}-{trial}.json").write_text(json.dumps(record))
            report = evaluate(output, "h2", identity)
            self.assertTrue(report["cases"][case["id"]]["valid"])
            self.assertTrue(report["cases"][case["id"]]["comparisons"]["latency"]["passed"])
            self.assertFalse(report["performance_gate_passed"])
            # A stale passing summary cannot conceal a recorded failed trial.
            (directory / "auto-1-failure.json").write_text('{"error":"fixture failure"}')
            report = evaluate(output, "h2", identity)
            self.assertFalse(report["cases"][case["id"]]["valid"])

    def test_histogram_is_bounded_and_percentiles_preserve_tail(self):
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

    def test_matrix_phases_include_medium_and_preserve_transition_order(self):
        case = cases("http1")[0]
        with patch.dict(os.environ, RUNWIRE_ADAPTIVE_CASE=json.dumps(case)):
            phases = phase_plan(180, 8, 64)
        self.assertEqual(tuple(p[0] for p in phases), PHASES)
        self.assertEqual([p[1] for p in phases], [1, 32, 256, 256, 1, 1])
        self.assertEqual([p[2] for p in phases], [180, 180, 2, 180, 2, 180])


if __name__ == "__main__":
    unittest.main()
