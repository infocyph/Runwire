"""Reject corrupt or misleading paired filesystem throughput evidence."""

import importlib.util
import json
import tempfile
import unittest
from pathlib import Path


SPEC = importlib.util.spec_from_file_location(
    "u11_worker_compare",
    Path(__file__).resolve().parents[1] / "benchmarks" / "u11_fs_worker_compare.py",
)
assert SPEC is not None and SPEC.loader is not None
module = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(module)


def measurement(workers: int, trial: int, sample: int) -> dict:
    return {
        "schemaVersion": 1,
        "releaseCertified": False,
        "correctnessPassed": True,
        "configuredWorkers": workers,
        "operations": 64,
        "payloadBytes": 65536,
        "verifiedRoundTrips": 64,
        "bytesReadWritten": 64 * 65536 * 2,
        "executorJobsCompleted": 64 * 3,
        "linuxProcessTreeAvailable": True,
        "throughputBytesPerSecond": (70_000_000 if workers == 2 else 60_000_000),
        "sampledProcessTreePeakRssBytes": 175_000_000 if workers == 2 else 120_000_000,
        "sampledMaxTickGapNanoseconds": 8_000_000,
        "sampledWorkerOccupancyRatio": 0.95,
        "processTreeSampleCount": 3,
        "sampledMaxBusyWorkers": workers,
        "executorPeakQueued": 32,
        "executorWorkers": workers,
        "trial": trial,
        "sample": sample,
    }


class WorkerEvidenceTest(unittest.TestCase):
    def setUp(self) -> None:
        self.temporary = tempfile.TemporaryDirectory()
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name)

    def write(self, workers: int, mutate=None) -> Path:
        path = self.root / f"{workers}.jsonl"
        records = [
            measurement(workers, trial, sample)
            for trial in range(1, 6)
            for sample in range(1, 9)
        ]
        if mutate is not None:
            mutate(records)
        path.write_text(
            "".join(json.dumps(item) + "\n" for item in records),
            encoding="utf-8",
        )
        return path

    def test_correct_5_by_8_proof_is_accepted(self) -> None:
        summary = module.summarize(module.records(self.write(1), 1))
        self.assertEqual(len(summary["trial_summaries"]), 5)
        self.assertEqual(summary["throughput_cv_percent"], 0.0)

    def test_missing_sample_is_rejected(self) -> None:
        with self.assertRaisesRegex(ValueError, "incomplete"):
            module.records(self.write(1, lambda items: items.pop()), 1)

    def test_duplicate_sample_is_rejected(self) -> None:
        with self.assertRaisesRegex(ValueError, "Duplicated"):
            module.records(self.write(1, lambda items: items.append(items[0].copy())), 1)

    def test_worker_or_queue_overflow_is_rejected(self) -> None:
        def violate(items):
            items[0]["sampledMaxBusyWorkers"] = 3
        with self.assertRaisesRegex(ValueError, "worker admission"):
            module.records(self.write(2, violate), 2)

    def test_unmeasured_process_tree_is_rejected(self) -> None:
        def violate(items):
            items[0]["processTreeSampleCount"] = 0
        with self.assertRaisesRegex(ValueError, "Missing samples"):
            module.records(self.write(1, violate), 1)

    def test_resource_bounds_are_finite_and_explicit(self) -> None:
        self.assertEqual(module.MAX_SAMPLED_TREE_RSS_BYTES, 384 * 1024 * 1024)
        self.assertEqual(module.MAX_LOOP_TICK_GAP_NS, 50_000_000)


if __name__ == "__main__":
    unittest.main()
