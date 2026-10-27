#!/usr/bin/env python3
"""Sample real Linux server supervisor and descendants while a client runs."""
import json
import os
import sys
import time
from pathlib import Path

pid = int(sys.argv[1])
keep = Path(sys.argv[2])
out = Path(sys.argv[3])
ticks_by_pid = {}
rss_peak = 0
fd_peak = 0
process_peak = 0
samples = 0
started = time.monotonic()
clock_ticks = os.sysconf("SC_CLK_TCK")


def tree(root):
    pending = [root]
    visited = set()
    while pending:
        current = pending.pop()
        if current in visited:
            continue
        visited.add(current)
        proc = Path("/proc") / str(current)
        if not proc.exists():
            continue
        yield current, proc
        children = proc / "task" / str(current) / "children"
        if children.is_file():
            pending.extend(int(v) for v in children.read_text().split())


while keep.exists():
    rss = 0
    fds = 0
    processes = 0
    for current, proc in tree(pid):
        try:
            stat = (proc / "stat").read_text().split(") ", 1)[1].split()
            cpu_ticks = int(stat[11]) + int(stat[12])
            text = (proc / "status").read_text()
            rss_kib = int(next(line.split()[1] for line in text.splitlines()
                               if line.startswith("VmRSS:")))
            descriptors = len(list((proc / "fd").iterdir()))
        except (OSError, IndexError, ValueError, StopIteration):
            continue
        first, _ = ticks_by_pid.get(current, (cpu_ticks, cpu_ticks))
        ticks_by_pid[current] = (first, cpu_ticks)
        rss += rss_kib * 1024
        fds += descriptors
        processes += 1
    if processes == 0:
        raise SystemExit("No Runwire server supervisor/worker tree sampled")
    rss_peak = max(rss_peak, rss)
    fd_peak = max(fd_peak, fds)
    process_peak = max(process_peak, processes)
    samples += 1
    time.sleep(0.15)

elapsed = time.monotonic() - started
cpu_seconds = sum(max(0, last - first) for first, last in ticks_by_pid.values()) / clock_ticks
out.write_text(json.dumps({
    "samples": samples, "duration_seconds": round(elapsed, 3),
    "cpu_percent": round(cpu_seconds / max(elapsed, 0.01) * 100, 3),
    "rss_peak_bytes": rss_peak, "fd_peak_count": fd_peak,
    "peak_process_count": process_peak
}, indent=2) + "\n")
