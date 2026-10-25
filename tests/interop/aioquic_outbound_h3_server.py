#!/usr/bin/env python3
"""Independent aioquic HTTP/3 server for the outbound Runwire client."""

import argparse
import asyncio
from pathlib import Path

from aioquic.asyncio import serve
from aioquic.asyncio.protocol import QuicConnectionProtocol
from aioquic.h3.connection import H3Connection
from aioquic.h3.events import HeadersReceived
from aioquic.quic.configuration import QuicConfiguration


class IndependentH3(QuicConnectionProtocol):
    expected_authority = b"localhost"

    def __init__(self, *args, **kwargs):
        super().__init__(*args, **kwargs)
        self.http = H3Connection(self._quic)
        self.answered = set()

    def quic_event_received(self, event):
        for h3_event in self.http.handle_event(event):
            if not isinstance(h3_event, HeadersReceived):
                continue
            if h3_event.stream_id in self.answered:
                continue
            fields = dict(h3_event.headers)
            if (
                fields.get(b":method") != b"GET"
                or fields.get(b":path") != b"/interop?q=1"
                or fields.get(b":authority") != self.expected_authority
            ):
                self.close(error_code=0x10E, reason_phrase="unexpected HTTP/3 request")
                return
            self.answered.add(h3_event.stream_id)
            body = b"runwire-outbound-h3-peer-ok"
            self.http.send_headers(
                h3_event.stream_id,
                [
                    (b":status", b"200"),
                    (b"content-type", b"text/plain"),
                    (b"content-length", str(len(body)).encode("ascii")),
                ],
            )
            self.http.send_data(h3_event.stream_id, body, end_stream=True)
            self.transmit()


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
