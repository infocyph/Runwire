#!/usr/bin/env python3
"""Emit measured runner affinity/hardware for Runwire's release comparator."""
import json
import os
import platform
import subprocess
from pathlib import Path

BASELINE = "7e9bf20ad0737a187dfed77b723791b141b36378"


def main():
    affinity = json.loads(
        subprocess.check_output(
            ["python3", str(Path(__file__).with_name("release_cpu_affinity.py"))],
            text=True,
        )
    )
    allowed = sorted(os.sched_getaffinity(0))
    if affinity["server_cpu"] not in allowed or affinity["client_cpu"] not in allowed:
        raise SystemExit("Selected benchmark core is not in the available cpuset")
    if not affinity["client_separate_physical_core"]:
        raise SystemExit("Load generator and server require different physical cores")
    cpuinfo = Path("/proc/cpuinfo")
    models = sorted({
        l.split(":", 1)[1].strip()
        for l in cpuinfo.read_text().splitlines()
        if l.startswith("model name")
    }) if cpuinfo.exists() else []
    result = {
        "schema": "runwire-comparator-topology-v1",
        "published_baseline": BASELINE,
        "candidate_source": os.environ.get("GITHUB_SHA", "local-unpinned"),
        "runner": {
            "machine": platform.machine(),
            "platform": platform.platform(),
            "kernel": platform.release(),
            "allowed_logical_cores": allowed,
            "cpu_models": models,
            "php_version": subprocess.check_output(
                ["php", "-r", "echo PHP_VERSION;"], text=True
            ),
            "python_version": platform.python_version(),
        },
        "isolation": affinity,
        "experiment": {
            "warmup_seconds": 30,
            "paired_trials": 5,
            "trial_seconds": 180,
            "baseline_and_candidate_share_server_cpu": True,
            "soak_seconds": 1800,
            "cv_ceiling": 0.025,
        },
    }
    print(json.dumps(result, indent=2, sort_keys=True))


if __name__ == "__main__":
    main()
