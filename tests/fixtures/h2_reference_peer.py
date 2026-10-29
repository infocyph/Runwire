#!/usr/bin/env python3
"""Independent hyper-h2 TLS/ALPN reference peer for Runwire client conformance.

This file uses the upstream hyper-h2 state machine, never Runwire HPACK/frame
code. The PR-only CI lane installs a pinned test-only hyper-h2 dependency.
"""
from __future__ import annotations

import socket
import ssl
import sys
import time

from h2.config import H2Configuration
from h2.connection import H2Connection
from h2.events import RequestReceived
from h2.settings import SettingCodes

if len(sys.argv) not in (3, 4) or (len(sys.argv) == 4 and sys.argv[3] not in ("http1", "deny")):
    raise SystemExit("Usage: h2_reference_peer.py certificate.pem private.pem [http1|deny]")
http1_mode = len(sys.argv) == 4
deny_mode = http1_mode and sys.argv[3] == "deny"

context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
context.minimum_version = ssl.TLSVersion.TLSv1_2
context.set_alpn_protocols(["http/1.1"] if http1_mode else ["h2"])
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
        if deny_mode:
            # A strict H2 client may fail TLS ALPN or abort when it sees H1.
            # Neither outcome may put an HTTP request on this socket.
            stream.settimeout(3)
            try:
                forbidden = stream.recv(4_096)
            except (OSError, TimeoutError):
                forbidden = b""
            if forbidden:
                raise RuntimeError("Strict H2 policy transmitted a forbidden H1 request")
        elif http1_mode:
            if stream.selected_alpn_protocol() != "http/1.1":
                raise RuntimeError("Independent fallback peer requires explicit TLS ALPN http/1.1")
            incoming = b""
            while b"\r\n\r\n" not in incoming:
                chunk = stream.recv(4_096)
                if not chunk or len(incoming) + len(chunk) > 16_384:
                    raise RuntimeError("Invalid or oversized independent HTTP/1.1 request")
                incoming += chunk
            if not incoming.startswith(b"GET /independent HTTP/1.1\r\n"):
                raise RuntimeError("Unexpected HTTP/1.1 fallback request line")
            if b"host: 127.0.0.1:" not in incoming.lower():
                raise RuntimeError("HTTP/1.1 fallback request did not retain origin")
            body = b"hyper-h2-h1-fallback"
            response = (
                b"HTTP/1.1 200 OK\r\n"
                b"Content-Type: text/plain\r\n"
                b"X-Reference-Server: hyper-h2-fallback\r\n"
                b"Content-Length: " + str(len(body)).encode() + b"\r\n"
                b"Connection: close\r\n\r\n" + body
            )
            stream.sendall(response)
            time.sleep(0.25)
        else:
            if stream.selected_alpn_protocol() != "h2":
                raise RuntimeError("H2 reference peer expected verified ALPN h2")
            connection = H2Connection(H2Configuration(client_side=False, header_encoding="utf-8"))
            # hyper-h2 defaults to emitting ENABLE_PUSH=0 in its server preface;
            # RFC 9113 forbids any server ENABLE_PUSH setting, so omit it.
            del connection.local_settings[SettingCodes.ENABLE_PUSH]
            connection.initiate_connection()
            stream.sendall(connection.data_to_send())
            responded = False
            while not responded:
                packet = stream.recv(65_536)
                if not packet:
                    raise RuntimeError("H2 request closed before reference validation")
                for event in connection.receive_data(packet):
                    if not isinstance(event, RequestReceived):
                        continue
                    fields = dict(event.headers)
                    if (fields.get(":method") != "GET" or fields.get(":path") != "/independent"):
                        raise RuntimeError("Unexpected H2 request headers")
                    if fields.get(":scheme") != "https" or fields.get(":authority") is None:
                        raise RuntimeError("Unverified H2 target pseudo headers")
                    connection.send_headers(event.stream_id, [
                        (":status", "200"),
                        ("content-type", "text/plain"),
                        ("x-reference-server", "hyper-h2"),
                    ])
                    connection.send_data(event.stream_id, b"hyper-h2-reference", end_stream=True)
                    responded = True
                pending = connection.data_to_send()
                if pending:
                    stream.sendall(pending)
            time.sleep(0.25)  # Let the client observe END_STREAM before peer EOF.
print("reference-ok", file=sys.stderr)
