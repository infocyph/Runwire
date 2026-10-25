#!/usr/bin/env python3
"""Independent upstream hyper-h2 server proving two streams on one TLS socket."""
from __future__ import annotations

import socket
import ssl
import sys
import time

from h2.config import H2Configuration
from h2.connection import H2Connection
from h2.events import RequestReceived
from h2.settings import SettingCodes

if len(sys.argv) != 3:
    raise SystemExit("Expected certificate and private key")

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
        del connection.local_settings[SettingCodes.ENABLE_PUSH]
        connection.initiate_connection()
        stream.sendall(connection.data_to_send())

        requests = {}
        while len(requests) < 2:
            packet = stream.recv(65_536)
            if not packet:
                raise RuntimeError("Peer disconnected before concurrent H2 requests")
            for event in connection.receive_data(packet):
                if not isinstance(event, RequestReceived):
                    continue
                headers = dict(event.headers)
                if headers.get(":method") != "GET" or headers.get(":scheme") != "https":
                    raise RuntimeError("Peer sent an invalid H2 method or scheme")
                if headers.get(":authority") is None:
                    raise RuntimeError("Peer omitted original TLS origin")
                requests[event.stream_id] = headers.get(":path")
            pending = connection.data_to_send()
            if pending:
                stream.sendall(pending)

        if requests != {1: "/multiplex/one", 3: "/multiplex/two"}:
            raise RuntimeError(f"Expected two concurrent H2 streams, got {requests!r}")
        # Respond to stream three before stream one to expose false one-shot owners.
        connection.send_headers(3, [
            (":status", "200"),
            ("content-type", "text/plain"),
            ("x-reference-server", "hyper-h2-multiplex"),
        ])
        connection.send_data(3, b"hyper-h2-second", end_stream=True)
        connection.send_headers(1, [
            (":status", "200"),
            ("content-type", "text/plain"),
            ("x-reference-server", "hyper-h2-multiplex"),
        ])
        connection.send_data(1, b"hyper-h2-first", end_stream=True)
        stream.sendall(connection.data_to_send())
        time.sleep(0.25)

print("multiplex-reference-ok", file=sys.stderr)
