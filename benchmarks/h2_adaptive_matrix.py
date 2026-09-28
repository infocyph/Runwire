#!/usr/bin/env python3

import json
import math
import os
import socket
import ssl
import sys
import time
from typing import Any

from h2.config import H2Configuration
from h2.connection import H2Connection
from h2.settings import SettingCodes
from h2.events import (
    ConnectionTerminated,
    DataReceived,
    ResponseReceived,
    StreamEnded,
    StreamReset,
)


from adaptive_benchmark import Measurement, metadata, phase_plan, workload


class H2Bench:
    def __init__(self, port: int, server_pid: int, payload_bytes: int) -> None:
        self.port, self.server_pid, self.payload_bytes = port, server_pid, payload_bytes
        self.case = workload()
        self.active: dict[int, dict[str, Any]] = {}
        self.request_index = 0
        self.issued = 0
        self.connect()

    def connect(self) -> None:
        raw = socket.create_connection(("127.0.0.1", self.port), timeout=3.0)
        # Client WINDOW_UPDATE frames must not wait for the client TCP Nagle timer.
        raw.setsockopt(socket.IPPROTO_TCP, socket.TCP_NODELAY, 1)
        if self.case.get("scenario") == "pressure":
            raw.setsockopt(socket.SOL_SOCKET, socket.SO_RCVBUF, 65536)
        context = ssl.create_default_context()
        context.check_hostname = False
        context.verify_mode = ssl.CERT_NONE
        context.set_alpn_protocols(["h2"])
        self.sock = context.wrap_socket(raw, server_hostname="localhost")
        self.sock.settimeout(0.2)
        if self.sock.selected_alpn_protocol() != "h2":
            raise RuntimeError("Runwire adaptive H2 benchmark did not negotiate h2.")
        self.conn = H2Connection(config=H2Configuration(client_side=True, header_encoding="utf-8"))
        self.conn.initiate_connection()
        if self.case.get("scenario") == "flow":
            self.conn.update_settings({SettingCodes.INITIAL_WINDOW_SIZE: 16384})
        self.sock.sendall(self.conn.data_to_send())
        self.issued = 0

    def close(self) -> None:
        try:
            self.conn.close_connection()
            data = self.conn.data_to_send()
            if data:
                self.sock.sendall(data)
        finally:
            self.sock.close()

    def open_stream(self, slot: int) -> None:
        stream_id = self.conn.get_next_available_stream_id()
        sizes = [2, 1024, 16384, 65536] if self.case.get("scenario") == "mixed" else [self.payload_bytes]
        size = sizes[self.request_index % len(sizes)]
        self.request_index += 1
        self.issued += 1
        self.conn.send_headers(stream_id, [
            (":method", "GET"), (":scheme", "https"), (":authority", "localhost"),
            (":path", f"/benchmark/{size}" if self.case else "/benchmark"),
        ], end_stream=True)
        self.active[stream_id] = {
            "slot": slot, "started": time.perf_counter(), "status": None,
            "body": bytearray(), "expected": b"x" * size,
        }

    def run_phase(self, concurrency: int, duration: float) -> dict[str, Any]:
        if self.active or not 1 <= concurrency <= 100:
            raise RuntimeError("H2 phase needs no active streams and concurrency between 1 and 100.")
        measurement = Measurement(self.server_pid, concurrency, duration)
        deadline = time.monotonic() + duration
        delayed_reads = 0
        constrained_flow_bytes = 0
        rotations = 0
        waiting = list(range(concurrency))
        while self.active or waiting:
            if time.monotonic() > deadline + 5:
                raise RuntimeError(f"H2 phase did not drain: active={len(self.active)}, completed={len(measurement.latencies)}, remaining_bytes={sum(len(state['expected']) - len(state['body']) for state in self.active.values())}")
            # Rotate only after draining, below the unchanged 10,000-stream server limit.
            if not self.active and self.issued >= 8000:
                self.close()
                self.connect()
                rotations += 1
            if self.issued < 8000:
                for slot in waiting:
                    self.open_stream(slot)
                waiting = []
            outbound = self.conn.data_to_send()
            if outbound:
                self.sock.sendall(outbound)
            measurement.sample()
            if self.case.get("scenario") == "pressure":
                time.sleep(0.002)
                delayed_reads += 1
            try:
                data = self.sock.recv(16384 if self.case.get("scenario") == "pressure" else 65535)
            except socket.timeout:
                continue
            if not data:
                raise RuntimeError("H2 server closed an active TLS connection.")
            for event in self.conn.receive_data(data):
                if isinstance(event, ResponseReceived):
                    state = self.active[event.stream_id]
                    state["status"] = int(dict(event.headers)[":status"])
                elif isinstance(event, DataReceived):
                    state = self.active[event.stream_id]
                    if self.case.get("scenario") == "flow":
                        constrained_flow_bytes += event.flow_controlled_length
                    state["body"].extend(event.data)
                    if len(state["body"]) > len(state["expected"]):
                        raise RuntimeError("H2 response exceeded expected body size.")
                    self.conn.acknowledge_received_data(event.flow_controlled_length, event.stream_id)
                elif isinstance(event, StreamEnded):
                    state = self.active.pop(event.stream_id)
                    if state["status"] != 200 or state["body"] != state["expected"]:
                        raise RuntimeError("H2 response validation failed.")
                    measurement.complete(state["slot"], state["started"])
                    if time.monotonic() < deadline:
                        waiting.append(state["slot"])
                elif isinstance(event, (StreamReset, ConnectionTerminated)):
                    raise RuntimeError(f"H2 protocol failure: {event!r}")
            if time.monotonic() >= deadline:
                waiting = []
        result = measurement.result()
        result["observed"] = {"delayed_reads": delayed_reads, "constrained_flow_bytes": constrained_flow_bytes, "connection_rotations": rotations}
        return result


def main(port: int, server_pid: int, mode: str, phase_seconds: float, payload_bytes: int) -> None:
    if mode not in {"auto", "fixed", "latency", "throughput"}:
        raise ValueError("Invalid H2 policy mode.")
    if not math.isfinite(phase_seconds) or phase_seconds < 1 or not 1 <= payload_bytes <= 65536:
        raise ValueError("H2 adaptive phase or payload is invalid.")
    warmup_seconds = max(1.0, float(os.environ.get("RUNWIRE_ADAPTIVE_WARMUP_SECONDS", "1")))
    bench = H2Bench(port, server_pid, payload_bytes)
    try:
        if not bench.run_phase(workload().get("low", 8), warmup_seconds)["correctness_passed"]:
            raise RuntimeError("H2 warm-up failed correctness.")
        phases = {name: bench.run_phase(concurrency, duration)
                  for name, concurrency, duration in phase_plan(phase_seconds, 8, 64)}
    finally:
        bench.close()
    result = metadata("h2", mode, payload_bytes, warmup_seconds)
    result.update(phases=phases, correctness_passed=all(p["correctness_passed"] for p in phases.values()))
    print(json.dumps(result, indent=2, sort_keys=True, allow_nan=False))
    if not result["correctness_passed"]:
        raise SystemExit(1)


if __name__ == "__main__":
    if len(sys.argv) != 6:
        raise SystemExit("Usage: h2_adaptive_matrix.py <port> <server-pid> <mode> <phase-seconds> <payload-bytes>")
    main(int(sys.argv[1]), int(sys.argv[2]), sys.argv[3], float(sys.argv[4]), int(sys.argv[5]))
