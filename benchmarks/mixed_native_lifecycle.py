#!/usr/bin/env python3
"""Strict independent mixed-protocol native crash/reload/recycle acceptance.

Tests live TCP+TLS h1/h2, framed TCP and separate UDP on one Runwire
native supervisor. This acceptance is not a matched release performance proof.
"""

import asyncio
import json
import os
from pathlib import Path
import signal
import socket
import ssl
import subprocess
import sys
import tempfile
import time

from h2.config import H2Configuration
from h2.connection import H2Connection
from h2.events import ConnectionTerminated, DataReceived, ResponseReceived, StreamEnded, StreamReset


def available_port(udp=False):
    family = socket.SOCK_DGRAM if udp else socket.SOCK_STREAM
    with socket.socket(socket.AF_INET, family) as sock:
        sock.bind(("127.0.0.1", 0))
        return sock.getsockname()[1]


def event_log(path):
    if not path.exists():
        return []
    result = []
    for line in path.read_text().splitlines():
        try:
            result.append(json.loads(line))
        except json.JSONDecodeError as exc:
            raise RuntimeError("Corrupt native supervisor event record") from exc
    return result


async def wait_until(predicate, process, log_file, label, seconds=15):
    deadline = time.monotonic() + seconds
    while time.monotonic() < deadline:
        events = event_log(log_file)
        if predicate(events):
            return events
        if process.poll() is not None:
            raise RuntimeError(f"Supervisor exited before {label}: {process.returncode}")
        await asyncio.sleep(0.05)
    raise RuntimeError(f"Timed out waiting for {label}: {event_log(log_file)[-15:]!r}")


async def request_http1(port):
    context = ssl.create_default_context()
    context.check_hostname = False
    context.verify_mode = ssl.CERT_NONE
    context.set_alpn_protocols(["http/1.1"])
    # A reload-completed callback immediately requests manual worker recycle.
    # A TCP/TLS handshake may therefore be reset before any HTTP bytes are
    # sent. Retry only that pre-request boundary, never an admitted request.
    for attempt in range(3):
        try:
            reader, writer = await asyncio.wait_for(
                asyncio.open_connection(
                    "127.0.0.1", port, ssl=context, server_hostname="localhost"
                ),
                timeout=3,
            )
            break
        except (ConnectionResetError, ConnectionRefusedError):
            if attempt == 2:
                raise
            await asyncio.sleep(0.05)
    try:
        if writer.get_extra_info("ssl_object").selected_alpn_protocol() != "http/1.1":
            raise RuntimeError("H1 ALPN mismatch")
        writer.write(b"GET /benchmark/2 HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n")
        await writer.drain()
        data = await asyncio.wait_for(reader.read(-1), timeout=4)
        if b"200" not in data.split(b"\r\n", 1)[0] or not data.endswith(b"\r\n\r\nxx"):
            raise RuntimeError(f"H1 response corruption: {data[:150]!r}")
    finally:
        writer.close()
        await writer.wait_closed()


class GracefulH2GoAwayFilter:
    """Validate native GOAWAY without closing hyper-h2's active request decoder.

    hyper-h2 4.3 treats received GOAWAY as terminal and rejects later DATA
    frames, although HTTP/2 permits already accepted streams to finish. This
    strict frame-boundary filter validates GOAWAY itself and feeds all other
    unmodified wire frames into hyper-h2 for complete payload verification.
    """

    def __init__(self, last_accepted_stream):
        self.last_accepted_stream = last_accepted_stream
        self.pending = bytearray()
        self.goaways = 0

    def receive(self, data):
        self.pending.extend(data)
        decoded = bytearray()
        while len(self.pending) >= 9:
            length = int.from_bytes(self.pending[0:3], "big")
            frame_length = 9 + length
            if len(self.pending) < frame_length:
                break

            frame = bytes(self.pending[:frame_length])
            del self.pending[:frame_length]
            if frame[3] != 7:
                decoded.extend(frame)
                continue

            stream_id = int.from_bytes(frame[5:9], "big")
            if stream_id != 0 or length < 8:
                raise ConnectionError("Malformed native HTTP/2 GOAWAY frame")
            last_processed = int.from_bytes(frame[9:13], "big") & 0x7FFFFFFF
            error_code = int.from_bytes(frame[13:17], "big")
            if error_code != 0 or last_processed < self.last_accepted_stream:
                raise ConnectionError(
                    f"Native HTTP/2 GOAWAY invalidates accepted siblings: "
                    f"error={error_code}, last={last_processed}"
                )
            self.goaways += 1
        return bytes(decoded)

    def assert_complete(self):
        if self.pending:
            raise ConnectionError("Truncated HTTP/2 frame after graceful drain")


async def request_http2(port, _server_pid, slow_receiver_seconds=0.02, submitted=None):
    # Admit eight real independent H2 streams with different body lengths;
    # cancellation of a ninth stream must not abort accepted sibling traffic.
    expected = {stream: b"x" * (1024 if stream % 4 == 1 else 16384)
                for stream in range(1, 17, 2)}
    cancelled_id = 17
    tls = ssl.create_default_context()
    tls.check_hostname = False
    tls.verify_mode = ssl.CERT_NONE
    tls.set_alpn_protocols(["h2"])
    for attempt in range(3):
        headers_seen = set()
        completed = set()
        update_write_failed = False
        response_bodies = {stream: bytearray() for stream in expected}
        goaway_filter = GracefulH2GoAwayFilter(max(expected))
        writer = None
        try:
            reader, writer = await asyncio.wait_for(
                asyncio.open_connection("127.0.0.1", port, ssl=tls,
                                        server_hostname="localhost"), timeout=3
            )
            if writer.get_extra_info("ssl_object").selected_alpn_protocol() != "h2":
                raise RuntimeError("Native TLS worker did not negotiate h2 ALPN")
            h2 = H2Connection(H2Configuration(client_side=True, header_encoding="utf-8"))
            h2.initiate_connection()
            for stream, body in expected.items():
                h2.send_headers(stream, [
                    (":method", "GET"),
                    (":scheme", "https"),
                    (":authority", "localhost"),
                    (":path", f"/benchmark/{len(body)}"),
                ], end_stream=True)
            h2.send_headers(cancelled_id, [
                (":method", "GET"), (":scheme", "https"),
                (":authority", "localhost"), (":path", "/benchmark/16384"),
            ], end_stream=True)
            h2.reset_stream(cancelled_id, error_code=0x8)
            writer.write(h2.data_to_send())
            await writer.drain()
            # Do not treat client-side submission as server admission.
            # For the shutdown wave, wait for real response headers on all
            # eight streams before signaling SIGTERM to the native master.
            if submitted is None:
                await asyncio.sleep(slow_receiver_seconds)
            while len(completed) < len(expected):
                data = await asyncio.wait_for(reader.read(65535), 4)
                if not data:
                    raise ConnectionError("H2 transport retired before response completion")
                # Independently validate GOAWAY, but retain DATA decoding for
                # already accepted streams after graceful server drain.
                for event in h2.receive_data(goaway_filter.receive(data)):
                    if isinstance(event, (ResponseReceived, DataReceived, StreamEnded, StreamReset)) \
                            and event.stream_id == cancelled_id:
                        if isinstance(event, DataReceived):
                            h2.acknowledge_received_data(event.flow_controlled_length, event.stream_id)
                        continue
                    if isinstance(event, ResponseReceived):
                        if event.stream_id not in expected or int(dict(event.headers).get(":status", "0")) != 200:
                            raise RuntimeError("H2 status/header corruption during native recycle")
                        headers_seen.add(event.stream_id)
                    elif isinstance(event, DataReceived):
                        if event.stream_id not in expected:
                            raise RuntimeError("Unexpected H2 response stream")
                        response_bodies[event.stream_id].extend(event.data)
                        if len(response_bodies[event.stream_id]) > len(expected[event.stream_id]):
                            raise RuntimeError("H2 body exceeded advertised length")
                        h2.acknowledge_received_data(event.flow_controlled_length, event.stream_id)
                    elif isinstance(event, StreamEnded):
                        if event.stream_id not in headers_seen or response_bodies[event.stream_id] != expected[event.stream_id]:
                            raise RuntimeError("H2 accepted response lost or corrupted on native reload")
                        completed.add(event.stream_id)
                    elif isinstance(event, StreamReset):
                        raise ConnectionError(f"H2 request reset during native reload: {event.stream_id}")
                    elif isinstance(event, ConnectionTerminated):
                        raise ConnectionError("Unfiltered terminal HTTP/2 control frame")
                if submitted is not None and len(headers_seen) == len(expected):
                    submitted.set()
                    submitted = None
                    # Keep accepted data under backpressure while shutdown
                    # starts; no unacknowledged stream is silently retried.
                    await asyncio.sleep(slow_receiver_seconds)
                # Once every admitted stream ended with its verified body,
                # do not write further WINDOW_UPDATE frames to a peer that
                # may already have completed its graceful TLS shutdown.
                # Sending post-completion flow-control frames can fail with
                # ECONNRESET even though every accepted byte arrived intact.
                if len(completed) == len(expected):
                    break
                outgoing = h2.data_to_send()
                if outgoing and not update_write_failed:
                    try:
                        writer.write(outgoing)
                        await writer.drain()
                    except (BrokenPipeError, ConnectionResetError):
                        # A server may already have sent graceful GOAWAY
                        # and TLS close while the independent client is
                        # writing an optional WINDOW_UPDATE. The write is
                        # not an admitted request or a response. Continue
                        # *reading and verifying every body and END_STREAM*;
                        # if any flow-control update was actually needed,
                        # a missing byte, reset or EOF still fails the wave.
                        update_write_failed = True
            goaway_filter.assert_complete()
            return len(completed)
        except (ConnectionError, OSError, ssl.SSLError):
            # A supervisor may retire a TLS session before any response is
            # admitted. Reconnect idempotent GETs, but NEVER retry partially
            # received bodies: that would hide lost accepted requests.
            if headers_seen or attempt >= 2:
                raise
            await asyncio.sleep(0.05)
        finally:
            if writer is not None:
                writer.close()
                try:
                    await asyncio.wait_for(writer.wait_closed(), 2)
                except (OSError, ssl.SSLError, asyncio.TimeoutError):
                    pass
    raise RuntimeError("H2 replacement never became ready")


async def request_tcp(port):
    reader, writer = await asyncio.open_connection("127.0.0.1", port)
    try:
        writer.write(b"ping\n")
        await writer.drain()
        if await asyncio.wait_for(reader.readline(), 3) != b"pong\n":
            raise RuntimeError("Framed TCP reply invalid")
    finally:
        writer.close()
        await writer.wait_closed()


def request_udp(port):
    with socket.socket(socket.AF_INET, socket.SOCK_DGRAM) as sock:
        sock.settimeout(3)
        sock.sendto(b"ping", ("127.0.0.1", port))
        payload, _addr = sock.recvfrom(4096)
        if payload != b"pong":
            raise RuntimeError("Raw UDP reply invalid")


async def complete_wave(tls_port, tcp_port, udp_port, server_pid,
                        h2_slow=0.02, h2_submitted=None):
    results = await asyncio.wait_for(
        asyncio.gather(
            request_http1(tls_port),
            request_http2(tls_port, server_pid, h2_slow, h2_submitted),
            request_tcp(tcp_port),
            asyncio.to_thread(request_udp, udp_port),
        ),
        timeout=12,
    )
    return {"http1": 1, "http2": results[1], "tcp": 1, "udp": 1,
            "cancelled_h2_streams": 1,
            "slow_h2_receiver_delay_ms": int(h2_slow * 1000)}


async def exercise():
    with tempfile.TemporaryDirectory() as workspace:
        root = Path(workspace)
        cert, key, events_file, log_path = [root / n for n in ("cert.pem", "key.pem", "events.jsonl", "server.log")]
        subprocess.run(
            ["openssl", "req", "-x509", "-newkey", "rsa:2048", "-nodes",
             "-days", "1", "-subj", "/CN=localhost", "-keyout", str(key), "-out", str(cert)],
            check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
        )
        tls_port, tcp_port, udp_port = available_port(), available_port(), available_port(True)
        server_log = log_path.open("w")
        process = subprocess.Popen(
            ["php", "tests/fixtures/mixed_native_runtime.php",
             str(tls_port), str(tcp_port), str(udp_port), str(cert), str(key), str(events_file)],
            stdout=server_log, stderr=subprocess.STDOUT,
        )
        observed = {}
        try:
            required = {"http:mixed-http", "tcp:mixed-tcp", "udp:mixed-udp"}
            await wait_until(
                lambda ev: required <= {e["group"] for e in ev if e.get("type") == "worker_ready"},
                process, events_file, "all three genuine native workers", 20,
            )
            observed["initial"] = await complete_wave(tls_port, tcp_port, udp_port, process.pid)

            records = event_log(events_file)
            target_pids = [
                e["pid"] for e in records
                if e.get("type") == "worker_ready" and e.get("group") == "http:mixed-http"
            ]
            if not target_pids or not isinstance(target_pids[-1], int) or target_pids[-1] <= 1:
                raise RuntimeError("Cannot ground real native HTTP worker PID")
            os.kill(target_pids[-1], signal.SIGKILL)
            await wait_until(
                lambda ev: len([e for e in ev if e.get("type") == "worker_ready"
                                and e.get("group") == "http:mixed-http"]) >= 2,
                process, events_file, "replacement after SIGKILL", 20,
            )
            observed["after_crash_restart"] = await complete_wave(tls_port, tcp_port, udp_port, process.pid)
            if not any(e.get("type") == "worker_restart_scheduled" for e in event_log(events_file)):
                raise RuntimeError("No native restart/backoff event after SIGKILL")

            # A real H2 workload is in flight when the signal bridge starts
            # the rolling reload; other three protocols run concurrently.
            during_reload = asyncio.create_task(
                complete_wave(tls_port, tcp_port, udp_port, process.pid)
            )
            await asyncio.sleep(0.1)
            os.kill(process.pid, signal.SIGHUP)
            observed["during_reload"] = await during_reload
            await wait_until(
                lambda ev: any(e.get("type") == "reload_completed" for e in ev),
                process, events_file, "native supervisor rolling reload", 25,
            )
            observed["after_reload"] = await complete_wave(tls_port, tcp_port, udp_port, process.pid)
            events = await wait_until(
                lambda ev: any(e.get("type") == "manual_recycle_requested" for e in ev),
                process, events_file, "manual H1/H2 recycle", 10,
            )
            if not any(e.get("type") == "manual_recycle_requested" and e.get("accepted") for e in events):
                raise RuntimeError("Native HTTP manual recycle was not accepted.")
            await wait_until(
                lambda ev: len([e for e in ev if e.get("type") == "worker_ready"
                                and e.get("group") == "http:mixed-http"]) >= 4,
                process, events_file, "manual recycle replacement generations", 25,
            )
            observed["after_manual_recycle"] = await complete_wave(tls_port, tcp_port, udp_port, process.pid)

            # Stop only after the H2 server has sent response headers for all
            # eight independently admitted streams. Client-side submission alone is not proof
            # of server admission and would make shutdown acceptance invalid. Do not treat merely opening sockets as
            # evidence that an active request survived native shutdown.
            h2_submitted = asyncio.Event()
            during_stop = asyncio.create_task(complete_wave(
                tls_port, tcp_port, udp_port, process.pid,
                h2_slow=0.25, h2_submitted=h2_submitted,
            ))
            await asyncio.wait_for(
                h2_submitted.wait(), timeout=5,
            )
            process.send_signal(signal.SIGTERM)
            observed["during_stop"] = await during_stop
            await asyncio.wait_for(asyncio.to_thread(process.wait), timeout=7)
            return {
                "correctness_passed": True,
                "independent_live_clients": ["tls-http1", "tls-h2", "framed-tcp", "udp"],
                "events": {event: sum(e.get("type") == event for e in event_log(events_file))
                           for event in ("worker_ready", "worker_restart_scheduled",
                                         "reload_started", "reload_completed",
                                         "worker_recycle_started", "manual_recycle_requested")},
                "waves": observed,
                "simultaneous_h2_streams_per_wave": 8,
                "cancelled_h2_streams_per_wave": 1,
                "slow_receiver_delay_ms": 20,
                "release_performance_certified": False,
            }
        finally:
            if process.poll() is None:
                process.send_signal(signal.SIGTERM)
            try:
                process.wait(timeout=6)
            except subprocess.TimeoutExpired:
                process.kill()
                process.wait()
            server_log.close()
            if process.returncode not in (0, -signal.SIGTERM):
                print("Native mixed runtime log:\n" + log_path.read_text()[-12000:], file=sys.stderr)


if __name__ == "__main__":
    try:
        print(json.dumps(asyncio.run(exercise()), indent=2, sort_keys=True))
    except Exception as error:
        print(f"Mixed native lifecycle FAILED: {type(error).__name__}: {error}", file=sys.stderr)
        raise
