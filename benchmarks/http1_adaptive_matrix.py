#!/usr/bin/env python3
"""H1 promotion client: validated plaintext/TLS responses and bounded connection reuse."""

import asyncio
import json
import os
import ssl
import sys
import time

from adaptive_benchmark import Measurement, metadata, phase_plan, workload


async def run_phase(port: int, pid: int, concurrency: int, duration: float) -> dict:
    case = workload()
    expected = b"x" * case["payload_bytes"]
    context = None
    if case["tls"]:
        context = ssl.create_default_context()
        context.check_hostname = False
        context.verify_mode = ssl.CERT_NONE
        context.set_alpn_protocols(["http/1.1"])
    measurement = Measurement(pid, concurrency, duration)
    deadline = time.monotonic() + duration
    connections = 0

    async def slot_work(slot: int) -> None:
        nonlocal connections
        writer = None
        try:
            while time.monotonic() < deadline:
                started = time.perf_counter()
                if writer is None:
                    reader, writer = await asyncio.wait_for(asyncio.open_connection(
                        "127.0.0.1", port, ssl=context,
                        server_hostname="localhost" if context else None,
                    ), timeout=5)
                    connections += 1
                    reused = 0
                writer.write(f"GET /benchmark/{len(expected)} HTTP/1.1\r\nHost: localhost\r\nConnection: keep-alive\r\n\r\n".encode())
                await writer.drain()
                head = await asyncio.wait_for(reader.readuntil(b"\r\n\r\n"), timeout=5)
                lines = head[:-4].split(b"\r\n")
                if lines[0].split()[1] != b"200":
                    raise RuntimeError("H1 response status failed validation")
                headers = {}
                for line in lines[1:]:
                    name, value = line.split(b":", 1)
                    name = name.lower()
                    if name in headers:
                        raise RuntimeError("H1 duplicate response header")
                    headers[name] = value.strip()
                if int(headers.get(b"content-length", b"-1")) != len(expected) or b"transfer-encoding" in headers:
                    raise RuntimeError("H1 response framing failed validation")
                body = await asyncio.wait_for(reader.readexactly(len(expected)), timeout=5)
                if body != expected:
                    raise RuntimeError("H1 response body failed validation")
                measurement.complete(slot, started)
                measurement.sample()
                reused += 1
                if reused >= case["keepalive"] or headers.get(b"connection", b"").lower() == b"close":
                    writer.close()
                    await writer.wait_closed()
                    writer = None
        finally:
            if writer:
                writer.close()
                await writer.wait_closed()

    async with asyncio.TaskGroup() as group:
        for slot in range(concurrency):
            group.create_task(slot_work(slot))
    result = measurement.result()
    result["observed"] = {"connections": connections}
    return result


async def main(port: int, pid: int, mode: str, seconds: float) -> None:
    warmup = float(os.environ["RUNWIRE_ADAPTIVE_WARMUP_SECONDS"])
    case = workload()
    if not (await run_phase(port, pid, case["low"], warmup))["correctness_passed"]:
        raise RuntimeError("H1 warm-up failed correctness")
    phases = {}
    for name, concurrency, duration in phase_plan(seconds, 1, 256):
        phases[name] = await run_phase(port, pid, concurrency, duration)
    result = metadata("http/1.1", mode, case["payload_bytes"], warmup)
    result.update(phases=phases, correctness_passed=all(p["correctness_passed"] for p in phases.values()))
    print(json.dumps(result, indent=2, allow_nan=False))
    if not result["correctness_passed"]:
        raise SystemExit(1)


if __name__ == "__main__":
    asyncio.run(main(int(sys.argv[1]), int(sys.argv[2]), sys.argv[3], float(sys.argv[4])))
