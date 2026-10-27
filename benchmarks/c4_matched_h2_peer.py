#!/usr/bin/env python3
"""Strict single-phase independent H2 real-wire benchmark: 30s warmup + 180s."""
import json
import sys
from pathlib import Path
sys.path.insert(0, str(Path(__file__).resolve().parent))
from h2_adaptive_matrix import H2Bench

if len(sys.argv) != 3:
    raise SystemExit("Usage: c4_matched_h2_peer.py PORT SUPERVISOR_PID")
bench = H2Bench(int(sys.argv[1]), int(sys.argv[2]), 768)
try:
    warm = bench.run_phase(8, 30.0)
    if warm["correctness_passed"] is not True:
        raise RuntimeError("Full H2 warmup did not pass correctness")
    result = bench.run_phase(8, 180.0)
finally:
    bench.close()
if (result["correctness_passed"] is not True or result["errors"] != 0
    or result["requests"] <= 0 or result["duration_seconds"] < 179.0
    or result["p99_ms"] <= 0 or result["rss_peak_bytes"] <= 0):
    raise RuntimeError("Independent real ALPN h2 long trial failed")
print(json.dumps(dict(result, warmup_seconds=30, phase_seconds=180,
                      protocol="h2-via-independent-hyper-h2"), sort_keys=True))
