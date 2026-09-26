#!/usr/bin/env python3

import asyncio
import json
import math
import os
import platform
import statistics
import sys
import time
from pathlib import Path


def cpu_model() -> str:
    try:
        for line in Path("/proc/cpuinfo").read_text().splitlines():
            if line.lower().startswith("model name"):
                return line.split(":", 1)[1].strip()
    except OSError:
        pass
    return platform.processor() or platform.machine() or "unknown"


def process_children(pid: int) -> list[int]:
    path = Path(f"/proc/{pid}/task/{pid}/children")
    try:
        return [int(value) for value in path.read_text().split() if int(value) > 1]
    except (OSError, ValueError):
        return []


def process_tree(root: int) -> list[int]:
    found: list[int] = []
    queue = [root]
    seen = {root}
    while queue:
        current = queue.pop(0)
        if Path(f"/proc/{current}").exists():
            found.append(current)
        for child in process_children(current):
            if child in seen:
                continue
            seen.add(child)
            queue.append(child)
    return found


def process_ticks(pid: int) -> int:
    try:
        fields = Path(f"/proc/{pid}/stat").read_text().split()
        return int(fields[13]) + int(fields[14])
    except (OSError, ValueError, IndexError):
        return 0


def process_rss(pid: int) -> int:
    try:
        for line in Path(f"/proc/{pid}/status").read_text().splitlines():
            if line.startswith("VmRSS:"):
                return int(line.split()[1]) * 1024
    except (OSError, ValueError, IndexError):
        pass
    return 0


def tree_ticks(root: int) -> int:
    return sum(process_ticks(pid) for pid in process_tree(root))


def tree_rss(root: int) -> int:
    return sum(process_rss(pid) for pid in process_tree(root))


def percentile(values: list[float], fraction: float) -> float:
    if not values:
        return 0.0
    ordered = sorted(values)
    rank = max(0, min(len(ordered) - 1, math.ceil(fraction * len(ordered)) - 1))
    return ordered[rank]


async def read_response(reader: asyncio.StreamReader) -> tuple[int, bytes, bool]:
    head = await asyncio.wait_for(reader.readuntil(b"\r\n\r\n"), timeout=2.0)
    lines = head.decode("latin1").split("\r\n")
    status = int(lines[0].split()[1])
    length = None
    close = False
    for line in lines[1:]:
        if ":" not in line:
            continue
        name, value = line.split(":", 1)
        if name.lower() == "content-length":
            parsed_length = int(value.strip())
            if length is not None and parsed_length != length:
                raise RuntimeError("Response has conflicting Content-Length fields.")
            length = parsed_length
        elif name.lower() == "connection":
            close = close or "close" in {token.strip().lower() for token in value.split(",")}
    if length is None:
        raise RuntimeError("Response omitted Content-Length.")
    if length < 0:
        raise RuntimeError("Response has a negative Content-Length.")
    body = await asyncio.wait_for(reader.readexactly(length), timeout=2.0)
    return status, body, close


def request_counters() -> dict[str, int]:
    return {
        "requests_total": 0,
        "completed_requests": 0,
        "successful_requests": 0,
        "errors_total": 0,
        "timeouts_total": 0,
        "validation_failures": 0,
        "reconnects_total": 0,
    }


async def warm_worker(host: str, port: int, deadline: float) -> None:
    counter = request_counters()
    await measure_worker(host, port, deadline, counter, None)
    if counter["errors_total"] or counter["timeouts_total"] or counter["validation_failures"]:
        raise RuntimeError("Warm-up received a failed or invalid response.")


async def measure_worker(
    host: str,
    port: int,
    deadline: float,
    counter: dict[str, int],
    latencies: list[float] | None,
) -> None:
    while time.perf_counter() < deadline:
        try:
            reader, writer = await asyncio.wait_for(asyncio.open_connection(host, port), timeout=2.0)
        except asyncio.TimeoutError:
            counter["requests_total"] += 1
            counter["timeouts_total"] += 1
            return
        except OSError:
            counter["requests_total"] += 1
            counter["errors_total"] += 1
            return

        try:
            while time.perf_counter() < deadline:
                # Account for attempts before I/O: EOF or reset cannot erase a request.
                counter["requests_total"] += 1
                started = time.perf_counter_ns()
                try:
                    writer.write(b"GET /benchmark HTTP/1.1\r\nHost: localhost\r\nConnection: keep-alive\r\n\r\n")
                    await writer.drain()
                    status, body, close = await read_response(reader)
                except asyncio.TimeoutError:
                    counter["timeouts_total"] += 1
                    return
                except (OSError, asyncio.IncompleteReadError, RuntimeError, ValueError):
                    counter["errors_total"] += 1
                    return

                elapsed_ms = (time.perf_counter_ns() - started) / 1_000_000
                if latencies is not None:
                    latencies.append(elapsed_ms)
                counter["completed_requests"] += 1
                if status == 200 and body == b"ok":
                    counter["successful_requests"] += 1
                else:
                    counter["validation_failures"] += 1
                # Only a fully consumed response can authorize orderly rotation.
                if close:
                    if time.perf_counter() < deadline:
                        counter["reconnects_total"] += 1
                    break
        finally:
            writer.close()
            try:
                await writer.wait_closed()
            except OSError:
                pass


async def sample_resources(root_pid: int, stop: asyncio.Event, peak: dict[str, int]) -> None:
    while not stop.is_set():
        peak["rss"] = max(peak["rss"], tree_rss(root_pid))
        try:
            await asyncio.wait_for(stop.wait(), timeout=0.1)
        except asyncio.TimeoutError:
            pass
    peak["rss"] = max(peak["rss"], tree_rss(root_pid))


async def main() -> None:
    if len(sys.argv) != 6:
        raise SystemExit(
            "Usage: http1_sustained_bench.py <port> <concurrency> <warmup-seconds> <duration-seconds> <server-pid>"
        )

    port = int(sys.argv[1])
    concurrency = int(sys.argv[2])
    warmup_seconds = float(sys.argv[3])
    duration_seconds = float(sys.argv[4])
    server_pid = int(sys.argv[5])
    if not 1 <= concurrency <= 1024:
        raise ValueError("concurrency must be between 1 and 1024")
    if warmup_seconds < 0 or duration_seconds <= 0:
        raise ValueError("warmup must be non-negative and duration must be positive")

    host = "127.0.0.1"
    if warmup_seconds > 0:
        warm_deadline = time.perf_counter() + warmup_seconds
        await asyncio.gather(*[
            warm_worker(host, port, warm_deadline)
            for _ in range(concurrency)
        ])

    counter = request_counters()
    latencies: list[float] = []
    peak = {"rss": tree_rss(server_pid)}
    start_ticks = tree_ticks(server_pid)
    stop_sampling = asyncio.Event()
    sampler = asyncio.create_task(sample_resources(server_pid, stop_sampling, peak))

    started = time.perf_counter()
    deadline = started + duration_seconds
    await asyncio.gather(*[
        measure_worker(host, port, deadline, counter, latencies)
        for _ in range(concurrency)
    ])
    elapsed = time.perf_counter() - started

    stop_sampling.set()
    await sampler
    end_ticks = tree_ticks(server_pid)

    failures = (
        counter["errors_total"]
        + counter["timeouts_total"]
        + counter["validation_failures"]
    )
    requests = counter["requests_total"]
    ticks_per_second = os.sysconf(os.sysconf_names["SC_CLK_TCK"])
    cpu_percent = 0.0
    if elapsed > 0 and ticks_per_second > 0:
        cpu_percent = max(0.0, (end_ticks - start_ticks) / ticks_per_second / elapsed * 100.0)

    extension_versions = {}
    raw_extensions = os.environ.get("RUNWIRE_EXTENSION_VERSIONS", "")
    for entry in raw_extensions.split(","):
        if "=" not in entry:
            continue
        name, version = entry.split("=", 1)
        if name and version:
            extension_versions[name] = version

    result = {
        "runtime": "runwire-native",
        "runtime_version": os.environ.get("RUNWIRE_RUNTIME_VERSION", "2.0-candidate"),
        "runtime_build": os.environ.get("RUNWIRE_RUNTIME_BUILD", "unknown"),
        "protocol": "http/1.1",
        "workload": "plaintext-keepalive",
        "hardware_id": f"{platform.machine()}::{cpu_model()}",
        "host_os": platform.platform(),
        "host_cpu": cpu_model(),
        "php_version": os.environ.get("RUNWIRE_PHP_VERSION", "unknown"),
        "instrumentation": os.environ.get("RUNWIRE_INSTRUMENTATION", "ci-smoke"),
        "tls": "off",
        "opcache": os.environ.get("RUNWIRE_OPCACHE", "unknown"),
        "connection_reuse": "keep-alive",
        "extension_versions": extension_versions,
        "workers": 1,
        "concurrency": concurrency,
        "duration_seconds": duration_seconds,
        "requests_total": requests,
        "completed_requests": counter["completed_requests"],
        "successful_requests": counter["successful_requests"],
        "throughput_rps": round(counter["successful_requests"] / elapsed, 3) if elapsed > 0 else 0.0,
        "latency_ms": {
            "p50": round(percentile(latencies, 0.50), 3),
            "p95": round(percentile(latencies, 0.95), 3),
            "p99": round(percentile(latencies, 0.99), 3),
        },
        "errors_total": counter["errors_total"],
        "timeouts_total": counter["timeouts_total"],
        "validation_failures": counter["validation_failures"],
        "reconnects_total": counter["reconnects_total"],
        "error_rate": round(failures / requests, 8) if requests > 0 else 1.0,
        "cpu_percent": round(cpu_percent, 3),
        "rss_peak_bytes": peak["rss"],
        "correctness_passed": (
            requests > 0
            and counter["completed_requests"] == requests
            and counter["successful_requests"] == requests
            and failures == 0
        ),
    }
    print(json.dumps(result, sort_keys=True))


if __name__ == "__main__":
    asyncio.run(main())
