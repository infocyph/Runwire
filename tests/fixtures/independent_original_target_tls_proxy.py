#!/usr/bin/env python3
"""Independent HTTP CONNECT TCP proxy and separately authenticated TLS origin.

The proxy has no TLS: the client must verify *the origin* certificate and SNI,
not mistake the proxy's identity for the endpoint. Never imports Runwire code.
"""
import json
import select
import socket
import ssl
import sys
import threading


def receive_header(conn):
    data = bytearray()
    while b"\r\n\r\n" not in data and len(data) < 8192:
        part = conn.recv(4096)
        if not part:
            raise RuntimeError("CONNECT disconnected before its header")
        data.extend(part)
    if len(data) >= 8192 or not data.endswith(b"\r\n\r\n"):
        raise RuntimeError("Invalid CONNECT framing")
    if data.decode("ascii") != (
        "CONNECT origin.example:443 HTTP/1.1\r\n"
        "Host: origin.example:443\r\n\r\n"
    ):
        raise RuntimeError("CONNECT origin authority was modified")


def origin(listener, cert, key, state):
    ctx = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
    ctx.load_cert_chain(cert, key)
    ctx.set_alpn_protocols(["http/1.1"])
    def sni(_connection, name, _context):
        state["sni"] = name
    ctx.set_servername_callback(sni)
    try:
        raw, _ = listener.accept()
        with raw:
            raw.settimeout(5)
            try:
                with ctx.wrap_socket(raw, server_side=True) as secured:
                    state["alpn"] = secured.selected_alpn_protocol()
                    if secured.recv(64) != b"ping-to-original\n":
                        raise RuntimeError("Origin did not receive correct tunneled content")
                    secured.sendall(b"pong-from-original\n")
                    state["received"] = True
            except (ssl.SSLError, ConnectionResetError, BrokenPipeError) as error:
                state["handshake_failed"] = str(error)[:150]
    except (OSError, RuntimeError) as error:
        state["origin_error"] = str(error)


def proxy_serve(conn, port):
    with conn:
        conn.settimeout(5)
        receive_header(conn)
        with socket.create_connection(("127.0.0.1", port), timeout=5) as target:
            target.setblocking(False)
            conn.setblocking(False)
            conn.sendall(b"HTTP/1.1 200 Connection Established\r\n\r\n")
            for _ in range(1000):
                ready, _, _ = select.select([conn, target], [], [], 0.01)
                if not ready:
                    continue
                for incoming in ready:
                    try:
                        part = incoming.recv(65536)
                    except BlockingIOError:
                        continue
                    except (BrokenPipeError, ConnectionResetError):
                        # The negative certificate-identity test deliberately
                        # aborts its TLS stream. A reset is an orderly end of
                        # this independent test relay, not a lost JSON result.
                        return
                    if not part:
                        return
                    outgoing = target if incoming is conn else conn
                    try:
                        outgoing.sendall(part)
                    except (BrokenPipeError, ConnectionResetError):
                        # The client can close immediately after rejecting the
                        # origin certificate; let main() join the origin peer
                        # and report its observed SNI/received-byte evidence.
                        return


def main():
    if len(sys.argv) != 3:
        raise ValueError("Usage: independent_proxy.py CERT KEY")
    cert, key = sys.argv[1:]
    state = {"sni": None, "alpn": None, "received": False}
    with socket.socket(socket.AF_INET, socket.SOCK_STREAM) as listener:
        listener.bind(("127.0.0.1", 0))
        listener.listen(2)
        listener.settimeout(6)
        port = listener.getsockname()[1]
        thread = threading.Thread(target=origin, args=(listener, cert, key, state))
        thread.start()
        try:
            with socket.socket(socket.AF_INET, socket.SOCK_STREAM) as proxy:
                proxy.bind(("127.0.0.1", 0))
                proxy.listen(2)
                proxy.settimeout(6)
                print(f"READY {proxy.getsockname()[1]}", flush=True)
                incoming, _ = proxy.accept()
                proxy_serve(incoming, port)
        finally:
            thread.join(timeout=6)
        if thread.is_alive():
            raise RuntimeError("Original TLS peer did not finish")
    print(json.dumps(state), flush=True)


if __name__ == "__main__":
    try:
        main()
    except (OSError, ValueError, RuntimeError, ssl.SSLError) as error:
        print(f"INDEPENDENT ORIGINAL TLS FAILED: {error}", file=sys.stderr)
        sys.exit(1)
