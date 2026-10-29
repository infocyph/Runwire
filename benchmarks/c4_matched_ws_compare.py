#!/usr/bin/env python3
"""Compare five full 180-second old/new WebSocket wire workloads, fail closed."""
import json
import math
import statistics
import sys
from pathlib import Path

dirpath = Path(sys.argv[1])
sha = sys.argv[2]
if len(sha) != 40 or not all(c in "0123456789abcdef" for c in sha):
    raise SystemExit("Invalid immutable Runwire candidate SHA")


def median(values):
    return statistics.median(values)


def load(group):
    measurements = []
    for i in range(1, 6):
        source = json.loads((dirpath / f"{group}-{i}.json").read_text())
        resource = json.loads((dirpath / f"{group}-{i}-resources.json").read_text())
        if (source.get("correctness_passed") is not True or
                source.get("trials") != 1 or source.get("duration_seconds_per_trial") != 180 or
                source.get("errors_total") != 0 or source.get("timeouts_total") != 0 or
                source.get("validation_failures") != 0 or
                source.get("successful_messages") != source.get("messages_total") or
                source.get("successful_messages", 0) <= 0 or
                resource.get("rss_peak_bytes", 0) <= 0 or
                resource.get("fd_peak_count", 0) <= 0 or
                resource.get("samples", 0) < 100):
            raise SystemExit(f"Incomplete or invalid real paired WebSocket measurement {group} trial {i}")
        measurements.append({
            "trial": i,
            "successful_rate": float(source["median_messages_per_second"]),
            "p99_ms": float(source["median_p99_ms"]),
            "peak_rss_bytes": int(resource["rss_peak_bytes"]),
            "peak_fd_count": int(resource["fd_peak_count"]),
            "server_cpu_percent": float(resource["cpu_percent"]),
            "successful_messages": int(source["successful_messages"])
        })
    rates = [row["successful_rate"] for row in measurements]
    cv = 100 * statistics.pstdev(rates) / statistics.mean(rates)
    return {"samples": measurements, "median_rps": round(median(rates), 3),
            "rps_cv_percent": round(cv, 3),
            "median_p99_ms": round(median([row["p99_ms"] for row in measurements]), 3),
            "peak_rss_bytes": max(row["peak_rss_bytes"] for row in measurements),
            "peak_fd_count": max(row["peak_fd_count"] for row in measurements)}


old = load("baseline")
new = load("candidate")
rps_ratio = new["median_rps"] / old["median_rps"]
p99 = new["median_p99_ms"]
base_p99 = old["median_p99_ms"]
rss_budget = old["peak_rss_bytes"] * 1.1 + 4194304
checks = {
    "variance": old["rps_cv_percent"] < 2.5 and new["rps_cv_percent"] < 2.5,
    "throughput": rps_ratio >= 0.95,
    "p99": p99 <= base_p99 * 1.15 or p99 <= base_p99 + 0.5,
    "rss": new["peak_rss_bytes"] <= rss_budget,
}
report = {
    "schema": 1,
    "workload": "real-independent-RFC6455-full-paired-180s",
    "published_baseline_sha": "7e9bf20ad0737a187dfed77b723791b141b36378",
    "candidate_sha": sha,
    "protocol": "websocket-rfc6455-over-h1",
    "source_isolated": True,
    "pairs": 5,
    "seconds_per_trial": 180,
    "trial_order_alternated": True,
    "baseline": old,
    "candidate": new,
    "rps_relative_delta_percent": round(100 * (rps_ratio - 1), 3),
    "p99_relative_delta_percent": round(100 * (p99 / base_p99 - 1), 3),
    "rss_budget_bytes": int(rss_budget),
    "checks": checks,
    "passed": all(checks.values()),
}
(dirpath / "comparison.json").write_text(json.dumps(report, indent=2) + "\n")
print(json.dumps(report, indent=2))
if not report["passed"]:
    sys.exit("RFC6455 full old/new release matched evidence exceeded frozen budgets")
