#!/usr/bin/env python3
"""Independent aioquic TLS 1.3 server for off-loop QUIC client-handshake acceptance."""

import argparse
import asyncio
from pathlib import Path

from aioquic.asyncio import serve
from aioquic.quic.configuration import QuicConfiguration


async def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("port", type=int)
    parser.add_argument("cert")
    parser.add_argument("key")
    parser.add_argument("ready")
    args = parser.parse_args()
    if args.port < 1 or args.port > 65535:
        raise ValueError("Invalid UDP port")

    configuration = QuicConfiguration(is_client=False, alpn_protocols=["h3"])
    configuration.load_cert_chain(args.cert, args.key)
    server = await serve("127.0.0.1", args.port, configuration=configuration)
    try:
        Path(args.ready).write_text("ready\n", encoding="utf-8")
        await asyncio.Event().wait()
    finally:
        server.close()


if __name__ == "__main__":
    asyncio.run(main())
