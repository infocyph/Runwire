#!/usr/bin/env python3
"""Strict independent mixed-protocol native crash/reload/recycle acceptance.

Tests live TCP+TLS h1/h2, QUIC h3, framed TCP and separate UDP on one Runwire
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

from aioquic.asyncio.client import connect
from aioquic.h3.connection import H3_ALPN
from aioquic.quic.configuration import QuicConfiguration
from h2.config import H2Configuration
from h2.connection import H2Connection
from h2.events import ConnectionTerminated, DataReceived, ResponseReceived, StreamEnded, StreamReset
from http3_adaptive_matrix import H3BenchClient


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
    reader, writer = await asyncio.open_connection(
        "127.0.0.1", port, ssl=context, server_hostname="localhost"
    )
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
        response_bodies = {stream: bytearray() for stream in expected}
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
                for event in h2.receive_data(data):
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
                    elif isinstance(event, ConnectionTerminated) and len(completed) < len(expected):
                        raise ConnectionError("Native H2 connection closed before all responses completed")
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
                if outgoing:
                    writer.write(outgoing)
                    await writer.drain()
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


async def request_http3(port, submitted=None):
    config = QuicConfiguration(is_client=True, alpn_protocols=H3_ALPN)
    config.verify_mode = ssl.CERT_NONE
    config.server_name = "localhost"
    async with connect("127.0.0.1", port, configuration=config,
                       create_protocol=H3BenchClient) as protocol:
        # Admit eight verified response streams before canceling a ninth.
        # This preserves native request-stream ID ordering through rollover.
        siblings = [
            asyncio.create_task(protocol.request(f"localhost:{port}", 16384, 0))
            for _ in range(8)
        ]
        try:
            await asyncio.sleep(0.01)
            if submitted is not None:
                submitted.set()
            cancelled_id = protocol._quic.get_next_available_stream_id()
            protocol.http.send_headers(cancelled_id, [
                (b":method", b"GET"), (b":scheme", b"https"),
                (b":authority", f"localhost:{port}".encode()), (b":path", b"/benchmark"),
            ], end_stream=True)
            protocol.transmit()
            await asyncio.sleep(0.01)
            protocol._quic.reset_stream(cancelled_id, error_code=0x10C)
            protocol.transmit()
            await asyncio.wait_for(asyncio.gather(*siblings), timeout=9)
            return 8
        finally:
            for sibling in siblings:
                if not sibling.done():
                    sibling.cancel()
            await asyncio.gather(*siblings, return_exceptions=True)


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
                        h2_slow=0.02, h2_submitted=None, h3_submitted=None):
    results = await asyncio.wait_for(
        asyncio.gather(
            request_http1(tls_port),
            request_http2(tls_port, server_pid, h2_slow, h2_submitted),
            request_http3(tls_port, h3_submitted),
            request_tcp(tcp_port),
            asyncio.to_thread(request_udp, udp_port),
        ),
        timeout=12,
    )
    return {"http1": 1, "http2": results[1], "http3": results[2], "tcp": 1, "udp": 1,
            "cancelled_h2_streams": 1, "cancelled_h3_streams": 1,
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
            required = {"http:mixed-http", "http3:mixed-http", "tcp:mixed-tcp", "udp:mixed-udp"}
            await wait_until(
                lambda ev: required <= {e["group"] for e in ev if e.get("type") == "worker_ready"},
                process, events_file, "all four genuine native workers", 20,
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
            # the rolling reload; other four protocols run concurrently.
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
                process, events_file, "manual coordinated H1/H2/H3 recycle", 10,
            )
            if not any(e.get("type") == "manual_recycle_requested" and e.get("accepted") for e in events):
                raise RuntimeError("Native HTTP + QUIC manual recycle was not accepted.")
            await wait_until(
                lambda ev: len([e for e in ev if e.get("type") == "worker_ready"
                                and e.get("group") == "http:mixed-http"]) >= 4
                           and len([e for e in ev if e.get("type") == "worker_ready"
                                    and e.get("group") == "http3:mixed-http"]) >= 3,
                process, events_file, "manual recycle replacement generations", 25,
            )
            observed["after_manual_recycle"] = await complete_wave(tls_port, tcp_port, udp_port, process.pid)

            # Stop only after the H2 server has sent response headers for all
            # eight independently admitted streams and H3 siblings have
            # begun transmission. Client-side submission alone is not proof
            # of server admission and would make shutdown acceptance invalid. Do not treat merely opening sockets as
            # evidence that an active request survived native shutdown.
            h2_submitted, h3_submitted = asyncio.Event(), asyncio.Event()
            during_stop = asyncio.create_task(complete_wave(
                tls_port, tcp_port, udp_port, process.pid,
                h2_slow=0.25, h2_submitted=h2_submitted, h3_submitted=h3_submitted,
            ))
            await asyncio.wait_for(
                asyncio.gather(h2_submitted.wait(), h3_submitted.wait()), timeout=5,
            )
            process.send_signal(signal.SIGTERM)
            observed["during_stop"] = await during_stop
            await asyncio.wait_for(asyncio.to_thread(process.wait), timeout=7)
            return {
                "correctness_passed": True,
                "independent_live_clients": ["tls-http1", "tls-h2", "quic-h3", "framed-tcp", "udp"],
                "events": {event: sum(e.get("type") == event for e in event_log(events_file))
                           for event in ("worker_ready", "worker_restart_scheduled",
                                         "reload_started", "reload_completed",
                                         "worker_recycle_started", "manual_recycle_requested")},
                "waves": observed,
                "simultaneous_h2_streams_per_wave": 8,
                "simultaneous_h3_streams_per_wave": 8,
                "cancelled_h2_streams_per_wave": 1,
                "cancelled_h3_streams_per_wave": 1,
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
