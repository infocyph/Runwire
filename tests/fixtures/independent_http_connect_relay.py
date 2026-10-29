#!/usr/bin/env python3
"""Independent authenticated TLS HTTP CONNECT proxy with a real TCP origin."""

import base64
import json
import socket
import ssl
import sys
import threading


REQUEST = b"ping-from-runwire"
RESPONSE = b"pong-from-origin"


def receive_header(peer):
    result = bytearray()
    while b"\r\n\r\n" not in result and len(result) <= 8192:
        data = peer.recv(2048)
        if not data:
            raise RuntimeError("CONNECT peer ended before the complete request")
        result.extend(data)
    if len(result) > 8192 or not result.endswith(b"\r\n\r\n"):
        raise RuntimeError("CONNECT request framing invalid or unbounded")
    return bytes(result)


def origin_server(listener, failures):
    try:
        with listener.accept()[0] as upstream:
            upstream.settimeout(4.0)
            received = upstream.recv(2048)
            if received != REQUEST:
                raise RuntimeError("Origin received incorrect tunneled bytes")
            upstream.sendall(RESPONSE)
    except Exception as exc:
        failures.append(str(exc))


def main():
    if len(sys.argv) != 4:
        raise ValueError("Usage: reference.py CERT KEY success|deny")
    certificate, key, mode = sys.argv[1:]
    if mode not in ("success", "deny"):
        raise ValueError("Invalid reference mode")

    tls = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
    tls.load_cert_chain(certificate, key)

    with socket.socket(socket.AF_INET, socket.SOCK_STREAM) as origin:
        origin.bind(("127.0.0.1", 0))
        origin.listen(1)
        origin.settimeout(4.0)
        origin_port = origin.getsockname()[1]
        with socket.socket(socket.AF_INET, socket.SOCK_STREAM) as proxy:
            proxy.bind(("127.0.0.1", 0))
            proxy.listen(1)
            proxy.settimeout(4.0)
            print(f"READY {proxy.getsockname()[1]} {origin_port}", flush=True)
            accepted, _ = proxy.accept()
            with accepted, tls.wrap_socket(accepted, server_side=True) as peer:
                peer.settimeout(4.0)
                header = receive_header(peer).decode("ascii")
                lines = header.split("\r\n")
                authority = f"127.0.0.1:{origin_port}"
                if lines[0] != f"CONNECT {authority} HTTP/1.1":
                    raise RuntimeError("CONNECT attempted the wrong target")
                fields = [line.split(":", 1) for line in lines[1:] if ":" in line]
                hosts = [v.strip() for k, v in fields if k.lower() == "host"]
                authentication = [v.strip() for k, v in fields if k.lower() == "proxy-authorization"]
                expected = "Basic " + base64.b64encode(b"user:secret").decode("ascii")
                if hosts != [authority]:
                    raise RuntimeError("CONNECT used incorrect or duplicate Host authority")
                authenticated = authentication == [expected]
                if not authenticated or mode == "deny":
                    peer.sendall(b"HTTP/1.1 407 Proxy Authentication Required\r\nContent-Length: 0\r\n\r\n")
                    print(json.dumps({"authenticated": authenticated, "relayed": False,
                                      "status": 407, "mode": mode}), flush=True)
                    return

                failures = []
                origin_thread = threading.Thread(target=origin_server, args=(origin, failures))
                origin_thread.start()
                try:
                    with socket.create_connection(("127.0.0.1", origin_port), timeout=4.0) as upstream:
                        peer.sendall(b"HTTP/1.1 200 Connection Established\r\n\r\n")
                        incoming = peer.recv(2048)
                        if incoming != REQUEST:
                            raise RuntimeError("Tunnel received incorrect payload")
                        upstream.sendall(incoming)
                        reply = upstream.recv(2048)
                        if reply != RESPONSE:
                            raise RuntimeError("Real origin returned incorrect tunneled response")
                        peer.sendall(reply)
                finally:
                    origin_thread.join(4.0)
                if origin_thread.is_alive() or failures:
                    raise RuntimeError("Real origin did not complete: " + str(failures))
                print(json.dumps({"authenticated": True, "relayed": True, "status": 200,
                                  "mode": mode, "origin_port": origin_port}), flush=True)


if __name__ == "__main__":
    try:
        main()
    except (OSError, RuntimeError, ValueError, ssl.SSLError) as error:
        print(f"INDEPENDENT CONNECT RELAY FAILED: {error}", file=sys.stderr)
        sys.exit(1)
