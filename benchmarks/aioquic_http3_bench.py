#!/usr/bin/env python3

import asyncio
import json
import ssl
import sys
import time
from pathlib import Path

from aioquic.asyncio.client import connect
from aioquic.h3.connection import H3_ALPN
from aioquic.quic.configuration import QuicConfiguration

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "tests" / "interop"))
from aioquic_http3_client import RunwireHttp3Client  # noqa: E402


async def run_batch(protocol: RunwireHttp3Client, authority: str, start: int, count: int) -> None:
    results = await asyncio.gather(*[
        protocol.get(authority, f"/benchmark?request={index}")
        for index in range(start, start + count)
    ])
    for status, body in results:
        if status != 200 or body != b"ok":
            raise RuntimeError(f"Unexpected Runwire benchmark response: status={status}, body={body!r}")


async def main(port: int, request_count: int, warmup_count: int) -> None:
    if request_count < 1 or request_count > 4_000:
        raise ValueError("request_count must be between 1 and 4000")
    if warmup_count < 0 or warmup_count > 1_000:
        raise ValueError("warmup_count must be between 0 and 1000")

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
        batch_size = 32
        completed = 0
        while completed < warmup_count:
            size = min(batch_size, warmup_count - completed)
            await run_batch(protocol, authority, completed, size)
            completed += size

        start_index = warmup_count
        measured = 0
        started = time.perf_counter_ns()
        while measured < request_count:
            size = min(batch_size, request_count - measured)
            await run_batch(protocol, authority, start_index + measured, size)
            measured += size
        elapsed_ns = time.perf_counter_ns() - started

    elapsed_seconds = elapsed_ns / 1_000_000_000
    result = {
        "amortized_us_per_request": round((elapsed_ns / request_count) / 1_000, 3),
        "elapsed_ms": round(elapsed_ns / 1_000_000, 3),
        "requests": request_count,
        "requests_per_second": round(request_count / elapsed_seconds, 2),
        "warmup_requests": warmup_count,
    }
    print(json.dumps(result, sort_keys=True))


if __name__ == "__main__":
    if len(sys.argv) != 4:
        raise SystemExit("Usage: aioquic_http3_bench.py <port> <request-count> <warmup-count>")
    asyncio.run(main(int(sys.argv[1]), int(sys.argv[2]), int(sys.argv[3])))
