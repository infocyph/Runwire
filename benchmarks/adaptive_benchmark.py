"""Shared measurement and workload contracts for the native adaptive benchmarks."""

import json
import math
import os
import time
from collections import Counter
from pathlib import Path
from typing import Any

def process_children(pid: int) -> list[int]:
    path = f"/proc/{pid}/task/{pid}/children"
    try:
        data = Path(path).read_text().strip()
    except OSError:
        return []
    return [int(value) for value in data.split() if value.isdigit() and int(value) > 1]


def process_tree(root: int) -> list[int]:
    queue = [root]
    seen = {root}
    found: list[int] = []
    while queue:
        pid = queue.pop(0)
        if os.path.isdir(f"/proc/{pid}"):
            found.append(pid)
        for child in process_children(pid):
            if child not in seen:
                seen.add(child)
                queue.append(child)
    return found


def process_ticks(pid: int) -> int:
    try:
        data = Path(f"/proc/{pid}/stat").read_text()
    except OSError:
        return 0
    end = data.rfind(")")
    if end < 0:
        return 0
    fields = data[end + 1 :].strip().split()
    return int(fields[11]) + int(fields[12]) if len(fields) > 12 else 0


def process_rss(pid: int) -> int:
    try:
        lines = Path(f"/proc/{pid}/status").read_text().splitlines()
    except OSError:
        return 0
    for line in lines:
        if line.startswith("VmRSS:"):
            parts = line.split()
            return int(parts[1]) * 1024 if len(parts) > 1 else 0
    return 0


def tree_ticks(root: int) -> int:
    return sum(process_ticks(pid) for pid in process_tree(root))


def tree_rss(root: int) -> int:
    return sum(process_rss(pid) for pid in process_tree(root))


class Latencies:
    """Bounded 10-microsecond histogram; never retain one object per request."""

    def __init__(self) -> None:
        self.buckets: Counter[int] = Counter()
        self.count = 0

    def append(self, milliseconds: float) -> None:
        if not math.isfinite(milliseconds) or milliseconds < 0 or milliseconds > 10000:
            raise ValueError("Request latency is outside the benchmark timeout bound")
        self.buckets[math.ceil(milliseconds * 100)] += 1
        self.count += 1

    def __len__(self) -> int:
        return self.count

    def percentile(self, fraction: float) -> float:
        rank = max(1, math.ceil(self.count * fraction))
        cumulative = 0
        for bucket, count in sorted(self.buckets.items()):
            cumulative += count
            if cumulative >= rank:
                return bucket / 100
        return 0.0


def workload() -> dict[str, Any]:
    raw = os.environ.get("RUNWIRE_ADAPTIVE_CASE")
    return json.loads(raw) if raw else {}


def phase_plan(seconds: float, low: int, high: int) -> list[tuple[str, int, float]]:
    case = workload()
    low, high = case.get("low", low), case.get("high", high)
    transition = min(2.0, seconds)
    phases = [("low_before", low, seconds)]
    if case:
        phases.append(("medium", case["medium"], seconds))
    return phases + [
        ("transition_up", high, transition), ("high", high, seconds),
        ("transition_down", low, transition), ("low_after", low, seconds),
    ]


def metadata(protocol: str, mode: str, payload: int, warmup: float) -> dict[str, Any]:
    case = workload()
    return {
        "protocol": protocol, "mode": mode,
        "workload": case.get("id", f"tls-{payload}-bytes"),
        "payload_bytes": payload,
        "runtime_build": os.environ.get("RUNWIRE_ADAPTIVE_BUILD", "unrecorded"),
        "environment": os.environ.get("RUNWIRE_ADAPTIVE_ENVIRONMENT", "unrecorded"),
        "warmup_seconds": warmup,
        **({"case": case, "trial": int(os.environ["RUNWIRE_ADAPTIVE_TRIAL"])} if case else {}),
    }


class Measurement:
    def __init__(self, pid: int, concurrency: int, duration: float) -> None:
        self.pid, self.concurrency, self.duration = pid, concurrency, duration
        self.started = time.monotonic()
        self.client_started = time.process_time()
        self.ticks = tree_ticks(pid)
        self.rss = tree_rss(pid)
        self.last_sample = self.started
        self.latencies = Latencies()
        self.slots = [0] * concurrency
        self.errors = 0

    def sample(self) -> None:
        now = time.monotonic()
        if now - self.last_sample >= 0.05:
            self.rss = max(self.rss, tree_rss(self.pid))
            self.last_sample = now

    def complete(self, slot: int, started: float) -> None:
        self.latencies.append((time.perf_counter() - started) * 1000)
        self.slots[slot] += 1

    def result(self) -> dict[str, Any]:
        elapsed = time.monotonic() - self.started
        count = len(self.latencies)
        return {
            "requests": count, "errors": self.errors,
            "duration_seconds": elapsed, "requested_duration_seconds": self.duration,
            "concurrency": self.concurrency, "throughput_rps": count / elapsed,
            "p50_ms": self.latencies.percentile(0.50),
            "p95_ms": self.latencies.percentile(0.95),
            "p99_ms": self.latencies.percentile(0.99),
            "cpu_percent": max(0, tree_ticks(self.pid) - self.ticks) / os.sysconf("SC_CLK_TCK") / elapsed * 100,
            "client_cpu_percent": (time.process_time() - self.client_started) / elapsed * 100,
            "rss_peak_bytes": max(self.rss, tree_rss(self.pid)),
            "fairness_ratio": max(self.slots) / max(1, min(self.slots)),
            "correctness_passed": count > 0 and self.errors == 0 and all(self.slots),
        }
