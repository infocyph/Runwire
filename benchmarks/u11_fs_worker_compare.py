#!/usr/bin/env python3
"""Compare real process-backed file I/O under one and two bounded workers.

This is a development measurement; it never certifies the 3.0 release.
"""
import json
import math
import statistics
import sys
from collections import defaultdict
from pathlib import Path

TRIALS = 5
SAMPLES = 8
OPERATIONS = 64
PAYLOAD_BYTES = 65536
# Conservative per-process-tree sampled limits for this development worker fixture.
MAX_SAMPLED_TREE_RSS_BYTES = 384 * 1024 * 1024
MAX_LOOP_TICK_GAP_NS = 50_000_000


def records(path: Path, workers: int) -> dict[int, list[dict]]:
    groups: dict[int, list[dict]] = defaultdict(list)
    for line in path.read_text(encoding="utf-8").splitlines():
        if not line.strip():
            continue
        item = json.loads(line)
        if (
            not isinstance(item, dict)
            or item.get("schemaVersion") != 1
            or item.get("releaseCertified") is not False
            or item.get("correctnessPassed") is not True
            or item.get("configuredWorkers") != workers
            or item.get("operations") != OPERATIONS
            or item.get("payloadBytes") != PAYLOAD_BYTES
            or item.get("verifiedRoundTrips") != OPERATIONS
            or item.get("bytesReadWritten") != OPERATIONS * PAYLOAD_BYTES * 2
            or item.get("executorJobsCompleted") != OPERATIONS * 3
            or item.get("linuxProcessTreeAvailable") is not True
            or not isinstance(item.get("trial"), int)
            or not isinstance(item.get("sample"), int)
        ):
            raise ValueError(f"Invalid or non-equivalent real filesystem profile in {path}")
        for field in (
            "throughputBytesPerSecond",
            "sampledProcessTreePeakRssBytes",
            "sampledMaxTickGapNanoseconds",
            "sampledWorkerOccupancyRatio",
            "processTreeSampleCount",
            "sampledMaxBusyWorkers",
            "executorPeakQueued",
            "executorWorkers",
            "executorJobsCompleted",
        ):
            value = item.get(field)
            if not isinstance(value, (int, float)) or not math.isfinite(value) or value < 0:
                raise ValueError(f"Invalid measurement {field}")
        if item["throughputBytesPerSecond"] <= 0 or item["sampledProcessTreePeakRssBytes"] <= 0:
            raise ValueError("Missing measured throughput or process-tree resident memory")
        if (item["processTreeSampleCount"] < 1
            or item["executorWorkers"] != workers
            or item["sampledMaxBusyWorkers"] > workers
            or item["executorPeakQueued"] > 128):
            raise ValueError("Missing samples or worker admission budget exceeded")
        if item["trial"] not in range(1, TRIALS + 1) or item["sample"] not in range(1, SAMPLES + 1):
            raise ValueError("Out-of-range trial/sample")
        if any(existing["sample"] == item["sample"] for existing in groups[item["trial"]]):
            raise ValueError("Duplicated profile sample")
        groups[item["trial"]].append(item)
    if len(groups) != TRIALS or any(len(samples) != SAMPLES for samples in groups.values()):
        raise ValueError("The five paired measurements are incomplete")
    return groups


def coefficient_of_variation(values: list[float]) -> float:
    return statistics.pstdev(values) / statistics.mean(values) * 100


def summarize(groups: dict[int, list[dict]]) -> dict:
    trials = []
    for trial in sorted(groups):
        batch = groups[trial]
        trials.append(
            {
                "trial": trial,
                "throughput_bytes_per_second": statistics.median(
                    sample["throughputBytesPerSecond"] for sample in batch
                ),
                "sampled_process_tree_peak_rss_bytes": max(
                    sample["sampledProcessTreePeakRssBytes"] for sample in batch
                ),
                "sampled_max_tick_gap_nanoseconds": max(
                    sample["sampledMaxTickGapNanoseconds"] for sample in batch
                ),
                "worker_occupancy_median": statistics.median(
                    sample["sampledWorkerOccupancyRatio"] for sample in batch
                ),
            }
        )
    rates = [trial["throughput_bytes_per_second"] for trial in trials]
    return {
        "samples_per_trial": SAMPLES,
        "trial_summaries": trials,
        "median_throughput_bytes_per_second": statistics.median(rates),
        "throughput_cv_percent": round(coefficient_of_variation(rates), 3),
    }


def main() -> int:
    if len(sys.argv) != 4:
        raise ValueError("Usage: compare.py one-worker.jsonl two-workers.jsonl output.json")
    one = summarize(records(Path(sys.argv[1]), 1))
    two = summarize(records(Path(sys.argv[2]), 2))
    speedup = two["median_throughput_bytes_per_second"] / one["median_throughput_bytes_per_second"]
    observed_peak_rss = max(
        entry["sampled_process_tree_peak_rss_bytes"]
        for summary in (one, two)
        for entry in summary["trial_summaries"]
    )
    observed_tick_gap = max(
        entry["sampled_max_tick_gap_nanoseconds"]
        for summary in (one, two)
        for entry in summary["trial_summaries"]
    )
    bounded_overhead = (
        observed_peak_rss <= MAX_SAMPLED_TREE_RSS_BYTES
        and observed_tick_gap <= MAX_LOOP_TICK_GAP_NS
    )
    variance_qualified = (
        one["throughput_cv_percent"] < 2.5 and two["throughput_cv_percent"] < 2.5
    )
    report = {
        "schema_version": 1,
        "scope": "development-real-process-backed-filesystem-pairs",
        "release_certified": False,
        "trials": TRIALS,
        "samples_per_trial": SAMPLES,
        "worker_counts": [1, 2],
        "same_operations_and_payload": True,
        "correctness_passed": True,
        "single_worker": one,
        "two_workers": two,
        "speedup_median": round(speedup, 4),
        "variance_qualified": variance_qualified,
        "sampled_max_process_tree_rss_bytes": observed_peak_rss,
        "sampled_max_event_loop_tick_gap_nanoseconds": observed_tick_gap,
        "sampled_tree_rss_budget_bytes": MAX_SAMPLED_TREE_RSS_BYTES,
        "sampled_tick_gap_budget_nanoseconds": MAX_LOOP_TICK_GAP_NS,
        "resource_budget_passed": bounded_overhead,
        "measured_improvement_candidate": variance_qualified and bounded_overhead and speedup > 1.05,
        "qualification_note": (
            "Only the specific warmed development workload; not sustained end-to-end "
            "file/mixed-network production certification"
        ),
    }
    Path(sys.argv[3]).write_text(json.dumps(report, indent=2) + "\n", encoding="utf-8")
    print(json.dumps(report, indent=2))
    return 0


if __name__ == "__main__":
    try:
        sys.exit(main())
    except (ValueError, OSError, json.JSONDecodeError) as failure:
        print(f"Filesystem profile comparison rejected: {failure}", file=sys.stderr)
        sys.exit(1)
