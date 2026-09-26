import asyncio
import importlib.util
import json
import os
from pathlib import Path
import sys
import time
import unittest
from unittest.mock import AsyncMock, Mock, patch


ROOT = Path(__file__).resolve().parents[2]
SPEC = importlib.util.spec_from_file_location(
    'http1_sustained_bench', ROOT / 'benchmarks/http1_sustained_bench.py',
)
bench = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(bench)


def counters():
    return dict.fromkeys((
        'requests_total', 'completed_requests', 'successful_requests',
        'errors_total', 'timeouts_total', 'validation_failures', 'reconnects_total',
    ), 0)


class SustainedAccountingTest(unittest.IsolatedAsyncioTestCase):
    async def exercise(self, responses, keep_open=False, cli=False, warm=False):
        observed = {'requests': 0, 'connections': 0, 'unexpected_reuse': 0}
        tasks = []

        async def serve(reader, writer):
            tasks.append(asyncio.current_task())
            observed['connections'] += 1
            try:
                for response in responses:
                    await reader.readuntil(b'\r\n\r\n')
                    observed['requests'] += 1
                    writer.write(response)
                    await writer.drain()
                if keep_open and await reader.read(1):
                    observed['unexpected_reuse'] += 1
            except (OSError, asyncio.IncompleteReadError):
                pass
            finally:
                writer.close()
                try:
                    await writer.wait_closed()
                except OSError:
                    pass

        server = await asyncio.start_server(serve, '127.0.0.1', 0)
        port = server.sockets[0].getsockname()[1]
        result = counters()
        async with server:
            if warm:
                await asyncio.wait_for(bench.warm_worker(
                    '127.0.0.1', port, time.perf_counter() + 0.25,
                ), 3)
            elif cli:
                process = await asyncio.create_subprocess_exec(
                    sys.executable, str(ROOT / 'benchmarks/http1_sustained_bench.py'),
                    str(port), '1', '0', '0.05', str(os.getpid()),
                    stdout=asyncio.subprocess.PIPE, stderr=asyncio.subprocess.PIPE,
                )
                out, err = await asyncio.wait_for(process.communicate(), 5)
                self.assertEqual(process.returncode, 0, err.decode())
                result = json.loads(out)
            else:
                await asyncio.wait_for(bench.measure_worker(
                    '127.0.0.1', port, time.perf_counter() + (0.25 if keep_open else 1.0), result, [],
                ), 3)
            if tasks:
                await asyncio.wait_for(asyncio.gather(*tasks), 3)
        return result, observed

    async def test_incomplete_responses_are_failed_attempts(self):
        good = b'HTTP/1.1 200 OK\r\nContent-Length: 2\r\n\r\nok'
        for bad in (
            b'',
            b'HTTP/1.1 200',
            b'HTTP/1.1 200 OK\r\nContent-Length: 2\r\n\r\n',
            b'HTTP/1.1 200 OK\r\nContent-Length: 2\r\n\r\no',
            b'HTTP/1.1 200 OK\r\nContent-Length: 2\r\nConnection: close\r\n\r\n',
        ):
            with self.subTest(response=bad):
                result, observed = await self.exercise([good, bad])
                self.assertEqual(observed['requests'], 2)
                self.assertEqual(result['requests_total'], 2)
                self.assertEqual(result['successful_requests'], 1)
                self.assertEqual(result['completed_requests'], 1)
                self.assertEqual(result['errors_total'], 1)
                self.assertEqual(result['reconnects_total'], 0)

    async def test_invalid_lengths_do_not_become_valid_on_header_scan(self):
        for headers in (
            b'Content-Length: -1\r\n',
            b'Content-Length: 3\r\nContent-Length: 2\r\n',
        ):
            with self.subTest(headers=headers):
                result, observed = await self.exercise([b'HTTP/1.1 200 OK\r\n' + headers + b'\r\nok'])
                self.assertEqual(observed['requests'], 1)
                self.assertEqual(result['requests_total'], 1)
                self.assertEqual(result['errors_total'], 1)
                self.assertEqual(result['completed_requests'], 0)

    async def test_announced_close_rotates_without_an_extra_request(self):
        result, observed = await self.exercise([
            b'HTTP/1.1 200 OK\r\nContent-Length: 2\r\nConnection: keep-alive, cLoSe\r\n\r\nok',
        ], keep_open=True)
        self.assertGreater(observed['connections'], 1)
        self.assertEqual(observed['unexpected_reuse'], 0)
        self.assertEqual(result['requests_total'], observed['requests'])
        self.assertEqual(result['successful_requests'], observed['requests'])
        self.assertEqual(result['errors_total'], 0)
        self.assertGreater(result['reconnects_total'], 0)

    async def test_reset_broken_pipe_and_timeout_count_once(self):
        for error in (ConnectionResetError(), BrokenPipeError(), asyncio.TimeoutError()):
            with self.subTest(error=type(error).__name__):
                writer = Mock()
                writer.drain = AsyncMock(side_effect=error)
                writer.wait_closed = AsyncMock()
                result = counters()
                with patch.object(bench.asyncio, 'open_connection', new=AsyncMock(
                    side_effect=[(Mock(), writer), OSError('unexpected retry')],
                )):
                    await bench.measure_worker('127.0.0.1', 1, time.perf_counter() + 1, result, [])
                self.assertEqual(result['requests_total'], 1)
                self.assertEqual(result['completed_requests'], 0)
                self.assertEqual(result['reconnects_total'], 0)
                self.assertEqual(result['timeouts_total'], int(isinstance(error, asyncio.TimeoutError)))
                self.assertEqual(result['errors_total'], int(not isinstance(error, asyncio.TimeoutError)))

    async def test_warmup_honors_announced_rotation(self):
        _, observed = await self.exercise([
            b'HTTP/1.1 200 OK\r\nContent-Length: 2\r\nConnection: close\r\n\r\nok',
        ], keep_open=True, warm=True)
        self.assertGreater(observed['connections'], 1)
        self.assertEqual(observed['unexpected_reuse'], 0)

    async def test_warmup_fails_on_missing_body(self):
        reader = asyncio.StreamReader()
        reader.feed_data(b'HTTP/1.1 200 OK\r\nContent-Length: 2\r\n\r\n')
        reader.feed_eof()
        writer = Mock()
        writer.drain = AsyncMock()
        writer.wait_closed = AsyncMock()
        with patch.object(bench.asyncio, 'open_connection', new=AsyncMock(return_value=(reader, writer))):
            with self.assertRaisesRegex(RuntimeError, 'Warm-up'):
                await bench.warm_worker('127.0.0.1', 1, time.perf_counter() + 1)

    async def test_cli_does_not_certify_dropped_bodies(self):
        result, observed = await self.exercise([
            b'HTTP/1.1 200 OK\r\nContent-Length: 2\r\n\r\nok',
            b'HTTP/1.1 200 OK\r\nContent-Length: 2\r\n\r\n',
        ], cli=True)
        self.assertEqual(observed['requests'], 2)
        self.assertEqual(result['requests_total'], 2)
        self.assertFalse(result['correctness_passed'])
        self.assertEqual(result['errors_total'], 1)
        self.assertEqual(result['error_rate'], 0.5)


if __name__ == '__main__':
    unittest.main()
