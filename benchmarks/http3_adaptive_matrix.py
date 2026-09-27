#!/usr/bin/env python3

import asyncio
import json
import math
import os
import ssl
import sys
import time
from pathlib import Path
from typing import Any

from aioquic.asyncio.client import connect
from aioquic.h3.connection import H3_ALPN
from aioquic.quic.configuration import QuicConfiguration

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "tests" / "interop"))
from aioquic_http3_client import RunwireHttp3Client  # noqa: E402


def process_children(pid: int) -> list[int]:
    path = f"/proc/{pid}/task/{pid}/children"
    try:
        data = open(path, "r", encoding="utf-8").read().strip()
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
        data = open(f"/proc/{pid}/stat", "r", encoding="utf-8").read()
    except OSError:
        return 0
    end = data.rfind(")")
    if end < 0:
        return 0
    fields = data[end + 1 :].strip().split()
    return int(fields[11]) + int(fields[12]) if len(fields) > 12 else 0


def process_rss(pid: int) -> int:
    try:
        lines = open(f"/proc/{pid}/status", "r", encoding="utf-8").read().splitlines()
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


def percentile(values: list[float], fraction: float) -> float:
    if not values:
        return 0.0
    ordered = sorted(values)
    rank = max(1, math.ceil(len(ordered) * fraction))
    return ordered[min(len(ordered) - 1, rank - 1)]


async def timed_get(
    protocol: RunwireHttp3Client,
    authority: str,
    path: str,
    slot: int,
) -> tuple[int, float, int, bytes]:
    started = time.perf_counter_ns()
    status, body = await protocol.get(authority, path)
    latency_ms = (time.perf_counter_ns() - started) / 1_000_000
    return slot, latency_ms, status, body


async def run_phase(
    protocol: RunwireHttp3Client,
    authority: str,
    concurrency: int,
    duration: float,
    server_pid: int,
    payload_bytes: int,
    request_index: int,
) -> tuple[dict[str, Any], int]:
    latencies: list[float] = []
    slot_completed = [0 for _ in range(concurrency)]
    errors = 0
    started = time.monotonic()
    deadline = started + duration
    start_ticks = tree_ticks(server_pid)
    peak_rss = tree_rss(server_pid)
    tasks: dict[asyncio.Task[tuple[int, float, int, bytes]], int] = {}

    def spawn(slot: int) -> None:
        nonlocal request_index
        path = f"/benchmark?request={request_index}"
        request_index += 1
        task = asyncio.create_task(timed_get(protocol, authority, path, slot))
        tasks[task] = slot

    for slot in range(concurrency):
        spawn(slot)

    while tasks:
        peak_rss = max(peak_rss, tree_rss(server_pid))
        done, _ = await asyncio.wait(
            tasks.keys(),
            timeout=0.2,
            return_when=asyncio.FIRST_COMPLETED,
        )
        if not done:
            if time.monotonic() > deadline + 5.0:
                raise RuntimeError("HTTP/3 adaptive phase did not drain before its hard deadline.")
            continue

        for task in done:
            slot = tasks.pop(task)
            try:
                _, latency_ms, status, body = task.result()
            except Exception:
                errors += 1
                continue

            if status != 200 or body != b"x" * payload_bytes:
                errors += 1
            latencies.append(latency_ms)
            slot_completed[slot] += 1
            if time.monotonic() < deadline:
                spawn(slot)

    elapsed = time.monotonic() - started
    end_ticks = tree_ticks(server_pid)
    ticks_per_second = int(os.sysconf("SC_CLK_TCK")) if hasattr(os, "sysconf") else 100
    cpu = max(0.0, (end_ticks - start_ticks) / max(1, ticks_per_second) / max(0.001, elapsed) * 100)
    completed = len(latencies)
    nonzero_slots = [count for count in slot_completed if count > 0]
    fairness = (
        max(nonzero_slots) / max(1, min(nonzero_slots))
        if nonzero_slots
        else float("inf")
    )

    return {
        "requests": completed,
        "throughput_rps": round(completed / max(0.001, elapsed), 3),
        "p50_ms": round(percentile(latencies, 0.50), 3),
        "p95_ms": round(percentile(latencies, 0.95), 3),
        "p99_ms": round(percentile(latencies, 0.99), 3),
        "cpu_percent": round(cpu, 3),
        "rss_peak_bytes": peak_rss,
        "fairness_ratio": round(fairness, 4),
        "errors": errors,
        "correctness_passed": completed > 0 and errors == 0 and all(count > 0 for count in slot_completed),
    }, request_index


async def main(port: int, server_pid: int, mode: str, phase_seconds: float, payload_bytes: int) -> None:
    if mode not in {"auto", "fixed"}:
        raise ValueError("HTTP/3 adaptive mode must be auto or fixed.")
    if phase_seconds < 1.0 or payload_bytes < 1:
        raise ValueError("HTTP/3 adaptive phase or payload is invalid.")

    configuration = QuicConfiguration(is_client=True, alpn_protocols=H3_ALPN)
    configuration.verify_mode = ssl.CERT_NONE
    configuration.server_name = "localhost"

    async with connect(
        "127.0.0.1",
        port,
        configuration=configuration,
        create_protocol=RunwireHttp3Client,
    ) as protocol:
        if not isinstance(protocol, RunwireHttp3Client):
            raise RuntimeError("aioquic returned an unexpected protocol implementation.")

        authority = f"localhost:{port}"
        request_index = 0
        warmup, request_index = await run_phase(
            protocol, authority, 8, 1.0, server_pid, payload_bytes, request_index
        )
        if not warmup["correctness_passed"]:
            raise RuntimeError("HTTP/3 adaptive warm-up failed correctness.")

        transition = max(1.0, min(2.0, phase_seconds / 2))
        high_seconds = max(phase_seconds, phase_seconds + 2.0)
        phases: dict[str, Any] = {}
        for name, concurrency, duration in [
            ("low_before", 8, phase_seconds),
            ("transition_up", 80, transition),
            ("high", 80, high_seconds),
            ("transition_down", 8, transition),
            ("low_after", 8, phase_seconds),
        ]:
            phases[name], request_index = await run_phase(
                protocol,
                authority,
                concurrency,
                duration,
                server_pid,
                payload_bytes,
                request_index,
            )

    correctness = all(bool(phase["correctness_passed"]) for phase in phases.values())
    result = {
        "protocol": "h3",
        "mode": mode,
        "phases": phases,
        "transition_latency_ms": {
            "up_p95": phases["transition_up"]["p95_ms"],
            "down_p95": phases["transition_down"]["p95_ms"],
        },
        "correctness_passed": correctness,
    }
    print(json.dumps(result, indent=2, sort_keys=True))
    if not correctness:
        raise SystemExit(1)


if __name__ == "__main__":
    if len(sys.argv) != 6:
        raise SystemExit(
            "Usage: http3_adaptive_matrix.py <port> <server-pid> <auto|fixed> <phase-seconds> <payload-bytes>"
        )
    asyncio.run(
        main(int(sys.argv[1]), int(sys.argv[2]), sys.argv[3], float(sys.argv[4]), int(sys.argv[5]))
    )
