#!/usr/bin/env python3
"""Bounded real aioquic HTTP/3 bidirectional cancellation storm with sibling progress.

No assertion here is a release throughput or full multi-protocol recycle claim.
"""

import asyncio
import json
import ssl
import sys

from aioquic.asyncio.client import connect
from aioquic.h3.connection import H3_ALPN
from aioquic.quic.configuration import QuicConfiguration
from http3_adaptive_matrix import H3BenchClient


async def exercise(port: int) -> dict:
    configuration = QuicConfiguration(is_client=True, alpn_protocols=H3_ALPN)
    configuration.verify_mode = ssl.CERT_NONE
    configuration.server_name = "localhost"

    successful = 0
    stopped = 0
    async with connect(
        "127.0.0.1", port, configuration=configuration, create_protocol=H3BenchClient
    ) as protocol:
        if not isinstance(protocol, H3BenchClient):
            raise RuntimeError("Unexpected HTTP/3 client transport.")

        for _round in range(3):
            previous = set(protocol.responses)
            tasks = [
                asyncio.create_task(
                    protocol.request(f"localhost:{port}", 65536, 0)
                )
                for _ in range(16)
            ]
            # Request tasks send their HTTP/3 headers before cancellation.
            await asyncio.sleep(0)
            active = [
                (stream_id, state)
                for stream_id, state in protocol.responses.items()
                if stream_id not in previous and not state["future"].done()
            ]
            if len(active) < 8:
                raise RuntimeError("Insufficient concurrent H3 streams for a fault test.")

            for stream_id, state in active[:6]:
                # Real QUIC transport STOP_SENDING, not just an asyncio task cancel.
                protocol._quic.stop_stream(stream_id, error_code=0x010C)
                # Terminate the sending half as well. A canceled request must
                # not leave an abandoned request-body/FIN waiter on the server.
                protocol._quic.reset_stream(stream_id, error_code=0x010C)
                state["future"].cancel()
                stopped += 1
            protocol.transmit()

            results = await asyncio.wait_for(
                asyncio.gather(*tasks, return_exceptions=True), timeout=12.0
            )
            for result in results:
                if result is None:
                    successful += 1
                elif not isinstance(result, asyncio.CancelledError):
                    raise RuntimeError(
                        f"H3 sibling request failed after cancellation: {result!r}"
                    )
            if successful < (_round + 1) * 10:
                raise RuntimeError("H3 healthy siblings failed to make progress.")

        # A fresh request on the same live QUIC session must remain usable.
        await asyncio.wait_for(
            protocol.request(f"localhost:{port}", 65536, 0), timeout=7.0
        )
        successful += 1

    return {
        "protocol": "h3",
        "independent_aioquic_client": True,
        "quic_stop_sending_sent": stopped,
        "quic_reset_stream_sent": stopped,
        "successful_siblings": successful,
        "fresh_session_request_completed": True,
        "correctness_passed": stopped >= 18 and successful >= 31,
        "promotion_certified": False,
    }


if __name__ == "__main__":
    if len(sys.argv) != 2:
        raise SystemExit("Usage: http3_fault_acceptance.py <port>")
    port = int(sys.argv[1])
    if not 1 <= port <= 65535:
        raise ValueError("Port outside valid range.")
    report = asyncio.run(exercise(port))
    if not report["correctness_passed"]:
        raise SystemExit("H3 reset/stalled-sibling acceptance failed.")
    print(json.dumps(report, sort_keys=True))
