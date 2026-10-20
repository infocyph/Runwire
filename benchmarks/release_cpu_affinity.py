#!/usr/bin/env python3
"""Choose benchmark CPU affinity from Linux's effective runner cpuset.

This is a benchmark-only preflight, not a Runwire runtime dependency.
"""

import json
import os
from pathlib import Path


def main() -> None:
    allowed = sorted(os.sched_getaffinity(0))
    physical = {}
    for cpu in allowed:
        topology = Path(f"/sys/devices/system/cpu/cpu{cpu}/topology")
        core = (topology / "core_id").read_text().strip()
        package = (topology / "physical_package_id").read_text().strip()
        physical.setdefault((package, core), cpu)

    if len(physical) < 2:
        raise SystemExit(
            "Matched certification needs two available distinct physical CPU cores."
        )

    cores = list(physical.values())
    data = {
        "mode": "pinned_distinct_physical_cores",
        "server_cpu": cores[0],
        "client_cpu": cores[1],
        "allowed_logical_cpu_count": len(allowed),
        "available_physical_core_count": len(physical),
        "same_server_cpu_for_baseline_and_candidate": True,
        "client_separate_physical_core": True,
    }
    print(json.dumps(data, separators=(",", ":"), sort_keys=True))


if __name__ == "__main__":
    main()
