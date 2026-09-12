#!/usr/bin/env python3

import asyncio
import ssl
import sys

from aioquic.asyncio.client import connect
from aioquic.h3.connection import H3_ALPN
from aioquic.quic.configuration import QuicConfiguration

from aioquic_http3_client import RunwireHttp3Client


async def main(port: int, request_count: int) -> None:
    if request_count < 1 or request_count > 2000:
        raise ValueError("request_count must be between 1 and 2000")

    configuration = QuicConfiguration(is_client=True, alpn_protocols=H3_ALPN)
    configuration.verify_mode = ssl.CERT_NONE
    configuration.server_name = "localhost"

    async with connect(
        "127.0.0.1",
        port,
        configuration=configuration,
        create_protocol=RunwireHttp3Client,
    ) as protocol:
        if not isinstance(protocol, RunwireHttp3Client):
            raise RuntimeError("aioquic returned an unexpected protocol implementation.")

        authority = f"localhost:{port}"
        completed = 0
        batch_size = 32
        while completed < request_count:
            upper = min(request_count, completed + batch_size)
            results = await asyncio.gather(*[
                protocol.get(authority, f"/soak?request={index}")
                for index in range(completed, upper)
            ])
            for status, body in results:
                if status != 200:
                    raise RuntimeError(f"Runwire returned HTTP status {status}, expected 200.")
                if body != b"runwire-soak-ok":
                    raise RuntimeError(f"Runwire returned unexpected soak body: {body!r}")
            completed = upper

    print(f"aioquic -> Runwire HTTP/3 soak: OK ({request_count} requests)")


if __name__ == "__main__":
    if len(sys.argv) != 3:
        raise SystemExit("Usage: aioquic_http3_soak.py <port> <request-count>")
    asyncio.run(main(int(sys.argv[1]), int(sys.argv[2])))
