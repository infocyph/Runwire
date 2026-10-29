#!/usr/bin/env python3
"""Independent strict post-GOAWAY HTTP/2 frame-regression checks."""

import unittest

from h2.config import H2Configuration
from h2.connection import H2Connection
from h2.events import DataReceived, ResponseReceived, StreamEnded
from hyperframe.frame import DataFrame, GoAwayFrame

from mixed_native_lifecycle import GracefulH2GoAwayFilter


class GracefulH2GoAwayFilterTest(unittest.TestCase):
    def sample(self):
        client = H2Connection(H2Configuration(client_side=True, header_encoding="utf-8"))
        server = H2Connection(H2Configuration(client_side=False, header_encoding="utf-8"))
        client.initiate_connection()
        server.initiate_connection()
        server.receive_data(client.data_to_send())
        client.receive_data(server.data_to_send())
        client.send_headers(1, [
            (":method", "GET"), (":path", "/"), (":scheme", "https"),
            (":authority", "localhost"),
        ], end_stream=True)
        server.receive_data(client.data_to_send())
        server.send_headers(1, [(":status", "200"), ("content-length", "2")])
        headers = server.data_to_send()
        goaway = GoAwayFrame(stream_id=0)
        goaway.last_stream_id = 1
        goaway.error_code = 0
        body = DataFrame(stream_id=1)
        body.data = b"ok"
        body.flags.add("END_STREAM")
        return client, headers, goaway, body

    def test_accepted_payload_after_fragmented_graceful_goaway(self):
        client, headers, goaway, body = self.sample()
        guard = GracefulH2GoAwayFilter(1)
        events = []
        raw = headers + goaway.serialize() + body.serialize()
        for offset in range(0, len(raw), 3):
            events.extend(client.receive_data(guard.receive(raw[offset:offset + 3])))
        guard.assert_complete()
        self.assertEqual(guard.goaways, 1)
        self.assertTrue(any(isinstance(e, ResponseReceived) for e in events))
        self.assertEqual(b"".join(e.data for e in events if isinstance(e, DataReceived)), b"ok")
        self.assertTrue(any(isinstance(e, StreamEnded) for e in events))

    def test_rejects_goaway_that_invalidates_accepted_stream(self):
        _, _, goaway, _ = self.sample()
        goaway.last_stream_id = 0
        with self.assertRaisesRegex(ConnectionError, "invalidates accepted siblings"):
            GracefulH2GoAwayFilter(1).receive(goaway.serialize())

    def test_rejects_goaway_errors(self):
        _, _, goaway, _ = self.sample()
        goaway.error_code = 2
        with self.assertRaisesRegex(ConnectionError, "invalidates accepted siblings"):
            GracefulH2GoAwayFilter(1).receive(goaway.serialize())

    def test_rejects_truncated_final_frame(self):
        guard = GracefulH2GoAwayFilter(1)
        guard.receive(bytes([0, 0, 6, 0, 0, 0, 0, 0, 1, 0]))
        with self.assertRaisesRegex(ConnectionError, "Truncated"):
            guard.assert_complete()


if __name__ == "__main__":
    unittest.main()
