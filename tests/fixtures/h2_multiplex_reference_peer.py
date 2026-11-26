#!/usr/bin/env python3
"""Independent upstream hyper-h2 server proving two streams on one TLS socket."""
from __future__ import annotations

import socket
import ssl
import sys
import time

from h2.config import H2Configuration
from h2.connection import H2Connection
from h2.events import PingAckReceived, RequestReceived, StreamReset
from hyperframe.frame import GoAwayFrame

modes = ("default", "flow", "cancel", "slow", "ping", "deadline", "head", "reset", "goaway")
if len(sys.argv) not in (3, 4) or (len(sys.argv) == 4 and sys.argv[3] not in modes):
    raise SystemExit("Expected certificate, private key, and optional reference mode")
mode = sys.argv[3] if len(sys.argv) == 4 else "default"
flow_mode = mode == "flow"
cancel_mode = mode in ("cancel", "deadline")
slow_mode = mode == "slow"

context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
context.minimum_version = ssl.TLSVersion.TLSv1_2
context.set_alpn_protocols(["h2"])
context.load_cert_chain(sys.argv[1], sys.argv[2])

with socket.socket(socket.AF_INET, socket.SOCK_STREAM) as server:
    server.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
    server.bind(("127.0.0.1", 0))
    server.listen(1)
    server.settimeout(8)
    print(server.getsockname()[1], flush=True)
    raw, _ = server.accept()
    with context.wrap_socket(raw, server_side=True) as stream:
        stream.settimeout(8)
        if stream.selected_alpn_protocol() != "h2":
            raise RuntimeError("Independent peer requires negotiated TLS h2")
        connection = H2Connection(H2Configuration(client_side=False, header_encoding="utf-8"))
        # Preserve the independent peer's default SETTINGS (including ENABLE_PUSH=0).
        connection.initiate_connection()
        stream.sendall(connection.data_to_send())

        requests = {}
        reset_seen = False
        while len(requests) < 2:
            packet = stream.recv(65_536)
            if not packet:
                raise RuntimeError("Peer disconnected before concurrent H2 requests")
            for event in connection.receive_data(packet):
                if isinstance(event, StreamReset) and event.stream_id == 1:
                    if event.error_code != 8:
                        raise RuntimeError("Expected client CANCEL error code")
                    reset_seen = True
                if not isinstance(event, RequestReceived):
                    continue
                headers = dict(event.headers)
                method = "HEAD" if mode == "head" and event.stream_id == 1 else "GET"
                if headers.get(":method") != method or headers.get(":scheme") != "https":
                    raise RuntimeError("Peer sent an invalid H2 method or scheme")
                if headers.get(":authority") is None:
                    raise RuntimeError("Peer omitted original TLS origin")
                requests[event.stream_id] = headers.get(":path")
            pending = connection.data_to_send()
            if pending:
                stream.sendall(pending)

        if requests != {1: "/multiplex/one", 3: "/multiplex/two"}:
            raise RuntimeError(f"Expected two concurrent H2 streams, got {requests!r}")
        if cancel_mode:
            # The genuine client must send RST_STREAM on request one while
            # keeping stream three and the authenticated TLS socket alive.
            stream.settimeout(5)
            while not reset_seen:
                packet = stream.recv(65_536)
                if not packet:
                    raise RuntimeError("Missing H2 RST_STREAM before sibling completion")
                for event in connection.receive_data(packet):
                    if isinstance(event, StreamReset) and event.stream_id == 1:
                        if event.error_code != 8:
                            raise RuntimeError("Expected client CANCEL error code")
                        reset_seen = True
            connection.send_headers(3, [
                (":status", "200"),
                ("content-type", "text/plain"),
                ("x-reference-server", "hyper-h2-multiplex"),
            ])
            connection.send_data(3, b"hyper-h2-second", end_stream=True)
            stream.sendall(connection.data_to_send())
            time.sleep(0.25)
            sys.exit(0)

        if mode == "ping":
            payload = b"runwire!"
            connection.ping(payload)
            stream.sendall(connection.data_to_send())
            while True:
                packet = stream.recv(65_536)
                if not packet:
                    raise RuntimeError("Peer disconnected before PING acknowledgment")
                events = connection.receive_data(packet)
                if any(isinstance(event, PingAckReceived) and event.ping_data == payload for event in events):
                    break

        if mode in ("reset", "goaway", "head"):
            def send_response(stream_id, body, head=False):
                headers = [
                    (":status", "200"),
                    ("content-type", "text/plain"),
                    ("x-reference-server", "hyper-h2-multiplex"),
                ]
                if head:
                    headers.append(("content-length", "4096"))
                connection.send_headers(stream_id, headers, end_stream=head)
                if not head:
                    connection.send_data(stream_id, body, end_stream=True)

            if mode == "reset":
                connection.reset_stream(1, error_code=8)
                send_response(3, b"hyper-h2-second")
            elif mode == "goaway":
                # hyper-h2's close_connection() prevents subsequent stream writes;
                # emit a standard GOAWAY frame directly to exercise graceful draining.
                goaway = GoAwayFrame(0)
                goaway.last_stream_id = 1
                goaway.error_code = 0
                stream.sendall(goaway.serialize())
                send_response(1, b"hyper-h2-first")
            else:
                send_response(3, b"hyper-h2-second")
                send_response(1, b"", head=True)
            stream.sendall(connection.data_to_send())
            time.sleep(0.25)
            sys.exit(0)

        if slow_mode:
            # Keep stream three pending while completing stream one first;
            # a connection-wide stall must not starve the healthy sibling.
            connection.send_headers(3, [
                (":status", "200"),
                ("content-type", "text/plain"),
                ("x-reference-server", "hyper-h2-multiplex"),
            ])
            connection.send_data(3, b"slow-", end_stream=False)
            stream.sendall(connection.data_to_send())
            time.sleep(0.10)
            connection.send_headers(1, [
                (":status", "200"),
                ("content-type", "text/plain"),
                ("x-reference-server", "hyper-h2-multiplex"),
            ])
            connection.send_data(1, b"hyper-h2-first", end_stream=True)
            stream.sendall(connection.data_to_send())
            time.sleep(0.10)
            connection.send_data(3, b"second", end_stream=True)
            stream.sendall(connection.data_to_send())
            time.sleep(0.25)
            sys.exit(0)

        # Respond to stream three before stream one to expose false one-shot owners.
        connection.send_headers(3, [
            (":status", "200"),
            ("content-type", "text/plain"),
            ("x-reference-server", "hyper-h2-multiplex"),
        ])
        if not flow_mode:
            connection.send_data(3, b"hyper-h2-second", end_stream=True)
        connection.send_headers(1, [
            (":status", "200"),
            ("content-type", "text/plain"),
            ("x-reference-server", "hyper-h2-multiplex"),
        ])
        if not flow_mode:
            connection.send_data(1, b"hyper-h2-first", end_stream=True)
            stream.sendall(connection.data_to_send())
            time.sleep(0.25)
        else:
            # Each body exceeds both HTTP/2's initial stream and connection
            # receive windows. A client that fails to return consumed credit
            # will deadlock without sending complete, correct responses.
            pending = {3: b"3" * 131_072, 1: b"1" * 131_072}
            offsets = {3: 0, 1: 0}
            stream.sendall(connection.data_to_send())
            deadline = time.monotonic() + 6.0
            while offsets:
                if time.monotonic() > deadline:
                    raise RuntimeError(f"H2 flow-pressure stalled at {offsets!r}")
                progressed = False
                for stream_id in (3, 1):
                    if stream_id not in offsets:
                        continue
                    available = min(
                        connection.local_flow_control_window(stream_id),
                        connection.max_outbound_frame_size,
                        8_192,
                        len(pending[stream_id]) - offsets[stream_id],
                    )
                    if available <= 0:
                        continue
                    start = offsets[stream_id]
                    end = start + available
                    connection.send_data(
                        stream_id,
                        pending[stream_id][start:end],
                        end_stream=end == len(pending[stream_id]),
                    )
                    offsets[stream_id] = end
                    if end == len(pending[stream_id]):
                        del offsets[stream_id]
                    progressed = True
                outgoing = connection.data_to_send()
                if outgoing:
                    stream.sendall(outgoing)
                if not progressed:
                    packet = stream.recv(65_536)
                    if not packet:
                        raise RuntimeError("H2 peer closed before credit replenishment")
                    connection.receive_data(packet)
            time.sleep(0.25)

print("multiplex-reference-ok", file=sys.stderr)
