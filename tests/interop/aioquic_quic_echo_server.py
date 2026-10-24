#!/usr/bin/env python3
"""Independent aioquic echo endpoint for validating worker-owned stream sessions."""

import argparse
import asyncio
from pathlib import Path

from aioquic.asyncio import serve
from aioquic.asyncio.protocol import QuicConnectionProtocol
from aioquic.quic.configuration import QuicConfiguration
from aioquic.quic.events import StreamDataReceived


class EchoProtocol(QuicConnectionProtocol):
    def quic_event_received(self, event) -> None:
        if isinstance(event, StreamDataReceived):
            # Only client-initiated bidirectional streams can carry replies.
            if (event.stream_id & 0x02) == 0:
                self._quic.send_stream_data(
                    event.stream_id, event.data, end_stream=event.end_stream
                )
                self.transmit()


async def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("port", type=int)
    parser.add_argument("cert")
    parser.add_argument("key")
    parser.add_argument("ready")
    args = parser.parse_args()
    configuration = QuicConfiguration(is_client=False, alpn_protocols=["runwire-echo"])
    configuration.load_cert_chain(args.cert, args.key)
    server = await serve(
        "127.0.0.1",
        args.port,
        configuration=configuration,
        create_protocol=EchoProtocol,
    )
    try:
        Path(args.ready).write_text("ready\n", encoding="utf-8")
        await asyncio.Event().wait()
    finally:
        server.close()


if __name__ == "__main__":
    asyncio.run(main())
