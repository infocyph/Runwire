#!/usr/bin/env python3
"""Independent aioquic echo endpoint for validating worker-owned stream sessions."""

import argparse
import asyncio
import json
from pathlib import Path

from aioquic.asyncio import serve
from aioquic.asyncio.protocol import QuicConnectionProtocol
from aioquic.quic.configuration import QuicConfiguration
from aioquic.quic.events import HandshakeCompleted, StreamDataReceived


class EchoProtocol(QuicConnectionProtocol):
    marker_path = None
    impair_post_handshake = False

    def __init__(self, *args, **kwargs) -> None:
        super().__init__(*args, **kwargs)
        self._handshake_complete = False
        self._post_handshake_datagrams = 0
        self._dropped_datagrams = 0
        self._delayed_datagrams = 0

    def datagram_received(self, data, addr) -> None:
        if not self.impair_post_handshake or not self._handshake_complete:
            super().datagram_received(data, addr)
            return
        self._post_handshake_datagrams += 1
        received = self._post_handshake_datagrams
        if received % 19 == 0:
            self._dropped_datagrams += 1
            return
        if received % 11 == 0:
            self._delayed_datagrams += 1
            asyncio.get_running_loop().call_later(0.025, self._deliver_delayed, data, addr)
            return
        super().datagram_received(data, addr)

    def _deliver_delayed(self, data, addr) -> None:
        super().datagram_received(data, addr)

    def quic_event_received(self, event) -> None:
        if isinstance(event, HandshakeCompleted):
            self._handshake_complete = True
            return
        if isinstance(event, StreamDataReceived):
            # Client-initiated unidirectional QUIC stream IDs have bit 1 set.
            if (event.stream_id & 0x02) != 0:
                buffers = self.__dict__.setdefault("_uni_buffers", {})
                received = buffers.get(event.stream_id, b"") + event.data
                if len(received) > 65_536:
                    self.close(error_code=1, reason_phrase="oversized fixture payload")
                    return
                if event.end_stream:
                    if self.marker_path is not None:
                        if self.impair_post_handshake:
                            metrics = {
                                "fault_profile": "post-handshake-udp-loss-reorder",
                                "post_handshake_datagrams": self._post_handshake_datagrams,
                                "dropped_datagrams": self._dropped_datagrams,
                                "delayed_datagrams": self._delayed_datagrams,
                                "verified_handshake": self._handshake_complete,
                            }
                            Path(str(self.marker_path) + ".fault.json").write_text(
                                json.dumps(metrics), encoding="utf-8"
                            )
                        Path(self.marker_path).write_bytes(received)
                    buffers.pop(event.stream_id, None)
                else:
                    buffers[event.stream_id] = received
                return

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
    parser.add_argument("unidirectional_marker")
    parser.add_argument("--impair-post-handshake", action="store_true")
    args = parser.parse_args()
    EchoProtocol.marker_path = args.unidirectional_marker
    EchoProtocol.impair_post_handshake = args.impair_post_handshake
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
