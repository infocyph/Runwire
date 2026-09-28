"""Real aioquic transport tests for the load client; not Runwire performance evidence."""

import asyncio
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[2] / "benchmarks"))
from aioquic.asyncio import serve
from aioquic.asyncio.protocol import QuicConnectionProtocol
from aioquic.h3.connection import H3_ALPN, H3Connection
from aioquic.h3.events import HeadersReceived, DataReceived
from aioquic.quic.configuration import QuicConfiguration
from adaptive_promotion_matrix import cases
from http3_adaptive_matrix import H3Session, run_phase


class ResponseServer(QuicConnectionProtocol):
    payload = 1024
    invalid = False

    def __init__(self, *args, **kwargs):
        super().__init__(*args, **kwargs)
        self.http = H3Connection(self._quic)
        self.uploads = {}

    def quic_event_received(self, event):
        for item in self.http.handle_event(event):
            if not isinstance(item, (HeadersReceived, DataReceived)):
                continue
            self.uploads.setdefault(item.stream_id, bytearray())
            if isinstance(item, DataReceived):
                self.uploads[item.stream_id].extend(item.data)
            if item.stream_ended:
                body = self.uploads.pop(item.stream_id)
                valid = body == b"u" * len(body)
                self.http.send_headers(item.stream_id, [(b":status", b"200" if valid else b"400"),
                    (b"x-upload-bytes", str(len(body)).encode())])
                self.http.send_data(item.stream_id, (b"!" if self.invalid else b"x") * self.payload, end_stream=True)
                self.transmit()


class H3ClientTest(unittest.IsolatedAsyncioTestCase):
    async def asyncSetUp(self):
        self.temp = tempfile.TemporaryDirectory()
        certificate = Path(self.temp.name) / "cert.pem"
        key = Path(self.temp.name) / "key.pem"
        subprocess.run(["openssl", "req", "-x509", "-newkey", "rsa:2048", "-nodes", "-days", "1",
                        "-subj", "/CN=localhost", "-keyout", str(key), "-out", str(certificate)],
                       check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        configuration = QuicConfiguration(is_client=False, alpn_protocols=H3_ALPN)
        configuration.load_cert_chain(str(certificate), str(key))
        self.server = await serve("127.0.0.1", 0, configuration=configuration, create_protocol=ResponseServer)
        self.port = self.server._transport.get_extra_info("sockname")[1]
        ResponseServer.invalid = False

    async def asyncTearDown(self):
        self.server.close()
        self.temp.cleanup()

    async def exercise(self, scenario):
        case = next(c for c in cases("h3") if c["scenario"] == scenario and c["payload_bytes"] == 1024)
        with patch.dict(os.environ, RUNWIRE_ADAPTIVE_CASE=json.dumps(case)):
            session = H3Session(self.port)
            try:
                result = await run_phase(session, os.getpid(), 4, 0.25, 1024)
                self.assertTrue(result["correctness_passed"])
                if scenario != "churn":
                    original = session.protocol
                    second = await run_phase(session, os.getpid(), 2, 0.1, 1024)
                    self.assertIs(session.protocol, original)
                    self.assertTrue(second["correctness_passed"])
                    session.issued = 8000
                    rotated = await run_phase(session, os.getpid(), 2, 0.1, 1024)
                    self.assertIsNot(session.protocol, original)
                    self.assertGreater(rotated["observed"]["connections"], 0)
                return result
            finally:
                await session.close()

    async def test_qpack_backlog_is_real_and_connection_state_survives_phases(self):
        result = await self.exercise("qpack")
        self.assertGreater(result["observed"]["qpack_delayed_headers"], 0)

    async def test_uploads_are_checked_end_to_end(self):
        result = await self.exercise("inbound")
        self.assertEqual(result["observed"]["upload_bytes"], result["requests"] * 65536)

    async def test_churn_opens_distinct_connections(self):
        result = await self.exercise("churn")
        self.assertGreaterEqual(result["observed"]["connections"], 4)

    async def test_invalid_response_fails_instead_of_counting_throughput(self):
        ResponseServer.invalid = True
        with self.assertRaises(ExceptionGroup):
            await self.exercise("outbound")


if __name__ == "__main__":
    unittest.main()
