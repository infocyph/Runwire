#!/usr/bin/env python3
"""Independent aioquic HTTP/3 server for the outbound Runwire client."""

import argparse
import asyncio
from pathlib import Path

from aioquic.asyncio import serve
from aioquic.asyncio.protocol import QuicConnectionProtocol
from aioquic.h3.connection import H3Connection
from aioquic.h3.events import DataReceived, HeadersReceived
from aioquic.quic.configuration import QuicConfiguration


class IndependentH3(QuicConnectionProtocol):
    expected_authority = b"localhost"

    def __init__(self, *args, **kwargs):
        super().__init__(*args, **kwargs)
        self.http = H3Connection(self._quic)
        self.answered = set()
        self.uploads = {}

    def respond(self, stream_id, with_trailers=False):
        body = b"runwire-outbound-h3-peer-ok"
        self.http.send_headers(
            stream_id,
            [
                (b":status", b"200"),
                (b"content-type", b"text/plain"),
                (b"content-length", str(len(body)).encode("ascii")),
            ],
        )
        self.http.send_data(stream_id, body, end_stream=not with_trailers)
        if with_trailers:
            self.http.send_headers(stream_id, [(b"x-server-check", b"verified")], end_stream=True)
        self.transmit()

    def quic_event_received(self, event):
        for h3_event in self.http.handle_event(event):
            stream_id = getattr(h3_event, "stream_id", None)
            if isinstance(h3_event, DataReceived):
                if stream_id not in self.uploads or h3_event.stream_ended:
                    self.close(error_code=0x10E, reason_phrase="unexpected HTTP/3 upload DATA")
                    return
                self.uploads[stream_id].extend(h3_event.data)
                if len(self.uploads[stream_id]) > 32768:
                    self.close(error_code=0x10E, reason_phrase="oversized HTTP/3 upload")
                    return
                continue
            if not isinstance(h3_event, HeadersReceived):
                continue
            if stream_id in self.uploads:
                if (dict(h3_event.headers) != {b"x-upload-finished": b"yes"}
                    or not h3_event.stream_ended
                    or bytes(self.uploads.pop(stream_id)) != b"upload-data-" * 2730 + b"end"):
                    self.close(error_code=0x10E, reason_phrase="invalid upload or trailers")
                    return
                self.respond(stream_id, with_trailers=True)
                continue
            if stream_id in self.answered:
                self.close(error_code=0x10E, reason_phrase="duplicate request headers")
                return
            fields = dict(h3_event.headers)
            if fields.get(b":authority") != self.expected_authority:
                self.close(error_code=0x10E, reason_phrase="unexpected authority")
                return
            method, path = fields.get(b":method"), fields.get(b":path")
            if method == b"GET" and path == b"/interop?q=1" and h3_event.stream_ended:
                self.answered.add(stream_id)
                self.respond(stream_id)
            elif method == b"POST" and path == b"/upload" and not h3_event.stream_ended:
                self.answered.add(stream_id)
                self.uploads[stream_id] = bytearray()
            else:
                self.close(error_code=0x10E, reason_phrase="unexpected HTTP/3 request")
                return

async def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("port", type=int)
    parser.add_argument("cert")
    parser.add_argument("key")
    parser.add_argument("ready")
    args = parser.parse_args()

    IndependentH3.expected_authority = f"localhost:{args.port}".encode("ascii")
    conf = QuicConfiguration(is_client=False, alpn_protocols=["h3"])
    conf.load_cert_chain(args.cert, args.key)
    server = await serve(
        "127.0.0.1", args.port, configuration=conf,
        create_protocol=IndependentH3,
    )
    try:
        Path(args.ready).write_text("ready\n", encoding="utf-8")
        await asyncio.Event().wait()
    finally:
        server.close()


if __name__ == "__main__":
    asyncio.run(main())
