#!/usr/bin/env python3
"""Five paired 180s strict RPS/CV/p99/RSS baseline-versus-candidate H2 gate."""
import json
import statistics
import sys
from pathlib import Path

root, sha = Path(sys.argv[1]), sys.argv[2]
if len(sha) != 40 or any(c not in "0123456789abcdef" for c in sha):
    raise SystemExit("Invalid candidate SHA")
def summarize(name):
    rows = []
    for trial in range(1, 6):
        m = json.loads((root / f"{name}-{trial}.json").read_text())
        r = json.loads((root / f"{name}-{trial}-resources.json").read_text())
        if (m.get("correctness_passed") is not True or m.get("errors") != 0
            or m.get("phase_seconds") != 180 or m.get("warmup_seconds") != 30
            or m.get("duration_seconds", 0) < 179 or m.get("requests", 0) <= 0
            or m.get("p99_ms", 0) <= 0 or r.get("rss_peak_bytes", 0) <= 0
            or r.get("fd_peak_count", 0) <= 0 or r.get("samples", 0) < 100):
            raise SystemExit(f"Incomplete {name} independent H2 trial {trial}")
        rows.append({"rps": m["throughput_rps"], "p99_ms": m["p99_ms"],
                     "rss_bytes": r["rss_peak_bytes"],
                     "server_cpu_percent": r["cpu_percent"], "fd": r["fd_peak_count"],
                     "client_cpu_percent": m["client_cpu_percent"]})
    rates = [a["rps"] for a in rows]
    return {"samples": rows, "median_rps": statistics.median(rates),
            "cv_percent": 100 * statistics.pstdev(rates) / statistics.mean(rates),
            "median_p99_ms": statistics.median(a["p99_ms"] for a in rows),
            "rss_peak_bytes": max(a["rss_bytes"] for a in rows),
            "client_cpu_headroom": all(a["client_cpu_percent"] < 85 for a in rows)}
base, candidate = summarize("baseline"), summarize("candidate")
ratio = candidate["median_rps"] / base["median_rps"]
checks = {
    "cv_both_below_2_5": max(base["cv_percent"], candidate["cv_percent"]) < 2.5,
    "no_rps_regression_gt_5pct": ratio >= 0.95,
    "p99_relative_or_absolute": candidate["median_p99_ms"] <= base["median_p99_ms"] * 1.15
                               or candidate["median_p99_ms"] <= base["median_p99_ms"] + .5,
    "rss_budget": candidate["rss_peak_bytes"] <= base["rss_peak_bytes"] * 1.10 + 4194304,
    "real_client_cpu_headroom": base["client_cpu_headroom"] and candidate["client_cpu_headroom"]
}
evidence = {"published_base": "7e9bf20ad0737a187dfed77b723791b141b36378",
            "candidate_sha": sha, "protocol": "real-TLS-ALPN-h2",
            "paired_trials": 5, "seconds_each": 180,
            "warmup_seconds": 30, "alternating_trial_order": True,
            "baseline": base, "candidate": candidate,
            "checks": checks, "passed": all(checks.values())}
(root / "comparison.json").write_text(json.dumps(evidence, indent=2) + "\n")
print(json.dumps(evidence, indent=2))
if not evidence["passed"]:
    sys.exit("Real H2 five-pair published 2.1.2 vs 3.0 regression gate failed")
