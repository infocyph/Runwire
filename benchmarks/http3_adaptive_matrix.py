#!/usr/bin/env python3
"""HTTP/3 steady/transition, upload, QPACK backlog and connection churn client."""

import asyncio
from contextlib import AsyncExitStack
import json
import math
import os
import ssl
import sys
import time
from typing import Any

from aioquic.asyncio.client import connect
from aioquic.asyncio.protocol import QuicConnectionProtocol
from aioquic.h3.connection import H3_ALPN, H3Connection
from aioquic.h3.events import DataReceived, HeadersReceived
from aioquic.quic.configuration import QuicConfiguration

from adaptive_benchmark import Measurement, metadata, phase_plan, workload


class DelayedEncoder(H3Connection):
    """Pinned aioquic 1.3.0 hook: delay encoder instructions, preserving their order."""

    def __init__(self, quic, transmit, delayed: bool) -> None:
        self.transmit = transmit
        self.delayed = delayed
        self.pending = bytearray()
        self.timer = None
        self.delayed_headers = 0
        super().__init__(quic)

    def _encode_headers(self, stream_id, headers):
        if not self.delayed:
            return super()._encode_headers(stream_id, headers)
        encoder, frame = self._encoder.encode(stream_id, headers)
        self._encoder_bytes_sent += len(encoder)
        self.pending.extend(encoder)
        if len(self.pending) > 65536:
            raise RuntimeError("Benchmark QPACK delay exceeded its buffer bound")
        if self.pending:
            if frame[0] != 0:
                self.delayed_headers += 1
            if self.timer is None:
                self.timer = asyncio.get_running_loop().call_later(0.01, self.flush_encoder)
        return frame

    def flush_encoder(self) -> None:
        if self.timer:
            self.timer.cancel()
            self.timer = None
        if self.pending:
            self._quic.send_stream_data(self._local_encoder_stream_id, bytes(self.pending))
            self.pending.clear()
            self.transmit()


class H3BenchClient(QuicConnectionProtocol):
    def __init__(self, *args, **kwargs) -> None:
        super().__init__(*args, **kwargs)
        self.http = DelayedEncoder(self._quic, self.transmit, workload().get("scenario") == "qpack")
        self.responses: dict[int, dict[str, Any]] = {}
        self.sequence = 0

    async def request(self, authority: str, payload: int, upload: int) -> None:
        stream = self._quic.get_next_available_stream_id()
        future = asyncio.get_running_loop().create_future()
        self.responses[stream] = {"future": future, "body": bytearray(), "status": 0,
                                  "upload": None, "expected_upload": upload, "payload": payload}
        headers = [(b":method", b"POST" if upload else b"GET"), (b":scheme", b"https"),
                   (b":authority", authority.encode()), (b":path", b"/benchmark"),
                   (b"content-length", str(upload).encode())]
        if self.http.delayed:
            # Reuse then replace values so encoder insertions continue throughout every phase.
            headers.append((b"x-benchmark-qpack", f"{self.sequence // 8}-".encode() + b"q" * 128))
        self.sequence += 1
        self.http.send_headers(stream, headers, end_stream=upload == 0)
        if upload:
            self.http.send_data(stream, b"u" * upload, end_stream=True)
        self.transmit()
        try:
            await asyncio.wait_for(future, timeout=5)
        finally:
            self.responses.pop(stream, None)

    def quic_event_received(self, event) -> None:
        for item in self.http.handle_event(event):
            if not isinstance(item, (HeadersReceived, DataReceived)):
                continue
            state = self.responses.get(item.stream_id)
            if state is None:
                continue
            if isinstance(item, HeadersReceived):
                headers = dict(item.headers)
                state["status"] = int(headers.get(b":status", b"0"))
                state["upload"] = int(headers.get(b"x-upload-bytes", b"-1"))
            else:
                state["body"].extend(item.data)
            future = state["future"]
            if future.done():
                continue
            if len(state["body"]) > state["payload"]:
                future.set_exception(RuntimeError("H3 response exceeded expected body size"))
            elif item.stream_ended:
                if (state["status"] != 200 or state["body"] != b"x" * state["payload"]
                        or state["upload"] != state["expected_upload"]):
                    future.set_exception(RuntimeError("H3 response or upload validation failed"))
                else:
                    future.set_result(None)

    def close(self, *args, **kwargs) -> None:
        self.http.flush_encoder()
        super().close(*args, **kwargs)


class H3Session:
    def __init__(self, port: int) -> None:
        self.port = port
        self.stack = AsyncExitStack()
        self.protocol = None
        self.issued = 0
        self.configuration = QuicConfiguration(is_client=True, alpn_protocols=H3_ALPN)
        self.configuration.verify_mode = ssl.CERT_NONE
        self.configuration.server_name = "localhost"

    async def acquire(self):
        if self.protocol is None or self.issued >= 8000:
            await self.stack.aclose()
            self.protocol = await self.stack.enter_async_context(connect(
                "127.0.0.1", self.port, configuration=self.configuration, create_protocol=H3BenchClient))
            self.issued = 0
        return self.protocol

    async def close(self) -> None:
        await self.stack.aclose()


async def run_phase(session: H3Session, pid: int, concurrency: int, duration: float, payload: int) -> dict:
    measurement = Measurement(pid, concurrency, duration)
    deadline = time.monotonic() + duration
    case = workload()
    upload = case.get("upload_bytes", 0)
    observed = {"connections": 0, "qpack_delayed_headers": 0, "upload_bytes": 0}

    async def drive(protocol, slots: list[int], request_limit: int) -> int:
        issued = 0
        previous_delays = protocol.http.delayed_headers

        async def slot_work(slot: int) -> None:
            nonlocal issued
            while time.monotonic() < deadline and issued < request_limit:
                issued += 1
                started = time.perf_counter()
                await protocol.request(f"localhost:{session.port}", payload, upload)
                measurement.complete(slot, started)
                measurement.sample()
                observed["upload_bytes"] += upload

        async with asyncio.TaskGroup() as group:
            for slot in slots:
                group.create_task(slot_work(slot))
        observed["qpack_delayed_headers"] += protocol.http.delayed_headers - previous_delays
        return issued

    async def churn(slot: int) -> None:
        while time.monotonic() < deadline:
            async with connect("127.0.0.1", session.port, configuration=session.configuration,
                               create_protocol=H3BenchClient) as protocol:
                observed["connections"] += 1
                await drive(protocol, [slot], 8)

    if case.get("scenario") == "churn":
        async with asyncio.TaskGroup() as group:
            for slot in range(concurrency):
                group.create_task(churn(slot))
    else:
        while time.monotonic() < deadline:
            previous = session.protocol
            protocol = await session.acquire()
            observed["connections"] += int(protocol is not previous)
            session.issued += await drive(protocol, list(range(concurrency)), 8000 - session.issued)
    result = measurement.result()
    result["observed"] = observed
    if case.get("scenario") == "qpack" and observed["qpack_delayed_headers"] == 0:
        raise RuntimeError("QPACK case did not exercise delayed dynamic header dependencies")
    return result


async def main(port: int, pid: int, mode: str, seconds: float, payload: int) -> None:
    if mode not in {"auto", "fixed", "latency", "throughput"}:
        raise ValueError("Invalid H3 policy mode")
    if not math.isfinite(seconds) or seconds < 1 or not 1 <= payload <= 65536:
        raise ValueError("Invalid H3 duration or payload")
    warmup = max(1.0, float(os.environ.get("RUNWIRE_ADAPTIVE_WARMUP_SECONDS", "1")))
    session = H3Session(port)
    try:
        if not (await run_phase(session, pid, workload().get("low", 8), warmup, payload))["correctness_passed"]:
            raise RuntimeError("H3 warm-up failed correctness")
        phases = {}
        for name, concurrency, duration in phase_plan(seconds, 8, 80):
            phases[name] = await run_phase(session, pid, concurrency, duration, payload)
    finally:
        await session.close()
    result = metadata("h3", mode, payload, warmup)
    result.update(phases=phases, correctness_passed=all(p["correctness_passed"] for p in phases.values()))
    print(json.dumps(result, indent=2, sort_keys=True, allow_nan=False))
    if not result["correctness_passed"]:
        raise SystemExit(1)


if __name__ == "__main__":
    asyncio.run(main(int(sys.argv[1]), int(sys.argv[2]), sys.argv[3], float(sys.argv[4]), int(sys.argv[5])))
