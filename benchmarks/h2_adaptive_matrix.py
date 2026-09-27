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
from h2.events import (
    ConnectionTerminated,
    DataReceived,
    ResponseReceived,
    StreamEnded,
    StreamReset,
)


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


class H2Bench:
    def __init__(self, port: int, server_pid: int, payload_bytes: int) -> None:
        raw = socket.create_connection(("127.0.0.1", port), timeout=3.0)
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
        self.sock.sendall(self.conn.data_to_send())
        self.server_pid = server_pid
        self.payload_bytes = payload_bytes
        self.active: dict[int, dict[str, Any]] = {}

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
        self.conn.send_headers(
            stream_id,
            [
                (":method", "GET"),
                (":scheme", "https"),
                (":authority", "localhost"),
                (":path", "/benchmark"),
            ],
            end_stream=True,
        )
        self.active[stream_id] = {
            "slot": slot,
            "started": time.perf_counter_ns(),
            "status": None,
            "body": bytearray(),
        }

    def run_phase(self, concurrency: int, duration: float) -> dict[str, Any]:
        if self.active:
            raise RuntimeError("H2 phase started with active streams.")
        if concurrency < 1 or concurrency > 100:
            raise ValueError("H2 phase concurrency must be between 1 and 100.")

        latencies: list[float] = []
        slot_completed = [0 for _ in range(concurrency)]
        errors = 0
        started = time.monotonic()
        deadline = started + duration
        hard_deadline = deadline + 5.0
        start_ticks = tree_ticks(self.server_pid)
        peak_rss = tree_rss(self.server_pid)

        for slot in range(concurrency):
            self.open_stream(slot)
        outbound = self.conn.data_to_send()
        if outbound:
            self.sock.sendall(outbound)

        while self.active:
            now = time.monotonic()
            if now >= hard_deadline:
                raise RuntimeError("H2 adaptive phase did not drain before its hard deadline.")
            peak_rss = max(peak_rss, tree_rss(self.server_pid))
            try:
                data = self.sock.recv(65535)
            except socket.timeout:
                continue
            if not data:
                raise RuntimeError("H2 adaptive server closed the TLS connection unexpectedly.")

            reopen: list[int] = []
            for event in self.conn.receive_data(data):
                if isinstance(event, ResponseReceived):
                    state = self.active.get(event.stream_id)
                    if state is not None:
                        for name, value in event.headers:
                            if name == ":status":
                                state["status"] = int(value)
                elif isinstance(event, DataReceived):
                    state = self.active.get(event.stream_id)
                    if state is not None:
                        state["body"].extend(event.data)
                    self.conn.acknowledge_received_data(event.flow_controlled_length, event.stream_id)
                elif isinstance(event, StreamEnded):
                    state = self.active.pop(event.stream_id, None)
                    if state is None:
                        continue
                    body = bytes(state["body"])
                    if state["status"] != 200 or body != b"x" * self.payload_bytes:
                        errors += 1
                    latency_ms = (time.perf_counter_ns() - int(state["started"])) / 1_000_000
                    latencies.append(latency_ms)
                    slot = int(state["slot"])
                    slot_completed[slot] += 1
                    if time.monotonic() < deadline:
                        reopen.append(slot)
                elif isinstance(event, StreamReset):
                    errors += 1
                    self.active.pop(event.stream_id, None)
                elif isinstance(event, ConnectionTerminated):
                    raise RuntimeError(f"H2 adaptive connection terminated: {event!r}")

            for slot in reopen:
                self.open_stream(slot)
            outbound = self.conn.data_to_send()
            if outbound:
                self.sock.sendall(outbound)

        elapsed = time.monotonic() - started
        end_ticks = tree_ticks(self.server_pid)
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
        }


def main(port: int, server_pid: int, mode: str, phase_seconds: float, payload_bytes: int) -> None:
    if mode not in {"auto", "fixed"}:
        raise ValueError("H2 adaptive mode must be auto or fixed.")
    if phase_seconds < 1.0 or payload_bytes < 1:
        raise ValueError("H2 adaptive phase or payload is invalid.")

    bench = H2Bench(port, server_pid, payload_bytes)
    try:
        warmup = bench.run_phase(8, 1.0)
        if not warmup["correctness_passed"]:
            raise RuntimeError("H2 adaptive warm-up failed correctness.")

        transition = max(1.0, min(2.0, phase_seconds / 2))
        high_seconds = max(phase_seconds, phase_seconds + 2.0)
        phases = {
            "low_before": bench.run_phase(8, phase_seconds),
            "transition_up": bench.run_phase(64, transition),
            "high": bench.run_phase(64, high_seconds),
            "transition_down": bench.run_phase(8, transition),
            "low_after": bench.run_phase(8, phase_seconds),
        }
    finally:
        bench.close()

    correctness = all(bool(phase["correctness_passed"]) for phase in phases.values())
    result = {
        "protocol": "h2",
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
            "Usage: h2_adaptive_matrix.py <port> <server-pid> <auto|fixed> <phase-seconds> <payload-bytes>"
        )
    main(int(sys.argv[1]), int(sys.argv[2]), sys.argv[3], float(sys.argv[4]), int(sys.argv[5]))
