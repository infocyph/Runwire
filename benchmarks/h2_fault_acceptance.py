#!/usr/bin/env python3
"""Independent h2 TLS client: stalled stream, sibling fairness and RST storm.

This is bounded protocol correctness/fault evidence, NOT a throughput certificate.
"""

import json
import socket
import ssl
import sys
import time

from h2.config import H2Configuration
from h2.connection import H2Connection
from h2.events import (
    ConnectionTerminated,
    DataReceived,
    ResponseReceived,
    StreamEnded,
    StreamReset,
)
from h2.settings import SettingCodes


def exercise(port: int) -> dict:
    raw = socket.create_connection(("127.0.0.1", port), timeout=3.0)
    raw.setsockopt(socket.IPPROTO_TCP, socket.TCP_NODELAY, 1)
    context = ssl.create_default_context()
    context.check_hostname = False
    context.verify_mode = ssl.CERT_NONE
    context.set_alpn_protocols(["h2"])
    with context.wrap_socket(raw, server_hostname="localhost") as conn:
        if conn.selected_alpn_protocol() != "h2":
            raise RuntimeError("Independent client did not negotiate h2.")
        conn.settimeout(0.25)
        h2 = H2Connection(H2Configuration(client_side=True, header_encoding="utf-8"))
        h2.initiate_connection()
        h2.update_settings({SettingCodes.INITIAL_WINDOW_SIZE: 8192})
        conn.sendall(h2.data_to_send())

        def request(size: int) -> int:
            stream = h2.get_next_available_stream_id()
            h2.send_headers(stream, [
                (":method", "GET"),
                (":scheme", "https"),
                (":authority", "localhost"),
                (":path", f"/benchmark/{size}"),
            ], end_stream=True)
            return stream

        stalled = request(65536)
        siblings = {request(1024): bytearray() for _ in range(12)}
        cancelled = [request(1024) for _ in range(12)]
        for stream in cancelled:
            h2.reset_stream(stream)
        conn.sendall(h2.data_to_send())

        started = time.monotonic()
        deadline = started + 9.0
        stalled_bytes = 0
        completed = 0
        while siblings:
            if time.monotonic() > deadline:
                raise RuntimeError(
                    f"H2 sibling stall: remaining={len(siblings)}, "
                    f"finished={completed}, stalled_bytes={stalled_bytes}"
                )
            try:
                chunk = conn.recv(65535)
            except socket.timeout:
                continue
            if not chunk:
                raise RuntimeError("TLS peer disconnected before sibling requests completed.")
            for event in h2.receive_data(chunk):
                if isinstance(event, ConnectionTerminated):
                    raise RuntimeError(f"H2 connection terminated unexpectedly: {event!r}")
                if isinstance(event, StreamReset) and event.stream_id in siblings:
                    raise RuntimeError("An unrelated sibling stream was reset.")
                if isinstance(event, ResponseReceived) and event.stream_id in siblings:
                    if int(dict(event.headers).get(":status", "0")) != 200:
                        raise RuntimeError("Sibling received a non-200 HTTP response.")
                if isinstance(event, DataReceived):
                    if event.stream_id == stalled:
                        stalled_bytes += len(event.data)
                        # Deliberately withhold stream WINDOW_UPDATE while siblings progress.
                    elif event.stream_id in siblings:
                        siblings[event.stream_id].extend(event.data)
                        if len(siblings[event.stream_id]) > 1024:
                            raise RuntimeError("Sibling response exceeded its declared size.")
                        h2.acknowledge_received_data(event.flow_controlled_length, event.stream_id)
                if isinstance(event, StreamEnded) and event.stream_id in siblings:
                    if siblings.pop(event.stream_id) != b"x" * 1024:
                        raise RuntimeError("Sibling body corrupted during stalled-stream pressure.")
                    completed += 1
            outgoing = h2.data_to_send()
            if outgoing:
                conn.sendall(outgoing)

        if completed != 12:
            raise RuntimeError("Missing completed sibling streams.")
        h2.reset_stream(stalled)
        conn.sendall(h2.data_to_send())
        return {
            "protocol": "h2",
            "independent_tls_client": True,
            "alpn": "h2",
            "cancelled_streams": len(cancelled) + 1,
            "siblings_completed": completed,
            "stalled_stream_received_bytes": stalled_bytes,
            "stalled_stream_flow_window": 8192,
            "elapsed_seconds": round(time.monotonic() - started, 4),
            "correctness_passed": True,
            "promotion_certified": False,
        }


if __name__ == "__main__":
    if len(sys.argv) != 2:
        raise SystemExit("Usage: h2_fault_acceptance.py <port>")
    port = int(sys.argv[1])
    if not 1 <= port <= 65535:
        raise ValueError("Port must be in range 1..65535.")
    print(json.dumps(exercise(port), sort_keys=True))
