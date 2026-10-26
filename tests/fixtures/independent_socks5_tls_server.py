#!/usr/bin/env python3
"""Independent RFC 1928/1929 TLS SOCKS5 reference peer for PHP integration tests.

Never imports Runwire protocol code. Authentication and DNS/address boundaries
are validated by this external Python process before it sends CONNECT success.
"""
import json
import socket
import ssl
import sys
import time


def receive_exact(sock, size):
    result = bytearray()
    while len(result) < size:
        part = sock.recv(size - len(result))
        if not part:
            raise RuntimeError("Peer closed before completing SOCKS5 message")
        result.extend(part)
    return bytes(result)


def main():
    if len(sys.argv) != 5:
        raise ValueError("Usage: reference.py CERT KEY HOST success|downgrade")
    certificate, private_key, expected_host, behavior = sys.argv[1:]
    if behavior not in ("success", "downgrade"):
        raise ValueError("Unsupported reference peer behavior")
    context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
    context.load_cert_chain(certificate, private_key)
    with socket.socket(socket.AF_INET, socket.SOCK_STREAM) as server:
        server.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
        server.bind(("127.0.0.1", 0))
        server.listen(1)
        server.settimeout(5)
        print(f"READY {server.getsockname()[1]}", flush=True)
        accepted, _ = server.accept()
        with accepted, context.wrap_socket(accepted, server_side=True) as peer:
            peer.settimeout(3)
            if receive_exact(peer, 3) != b"\x05\x01\x02":
                raise RuntimeError("Invalid password-only SOCKS5 greeting")
            if behavior == "downgrade":
                peer.sendall(b"\x05\x00")
                # Client must abort, not send a password after anonymous selection.
                try:
                    extra = peer.recv(4096)
                except (ConnectionResetError, ssl.SSLError, TimeoutError):
                    extra = b""
                if extra:
                    raise RuntimeError("SOCKS5 client disclosed data after auth downgrade")
                print(json.dumps({"mode": "downgrade", "extra_bytes": 0}), flush=True)
                return
            peer.sendall(b"\x05\x02")
            version, username_len = receive_exact(peer, 2)
            if version != 1 or receive_exact(peer, username_len) != b"user":
                raise RuntimeError("Bad RFC1929 username")
            password_len = receive_exact(peer, 1)[0]
            if receive_exact(peer, password_len) != b"secret":
                raise RuntimeError("Bad RFC1929 password")
            peer.sendall(b"\x01\x00")
            version, command, reserved, kind = receive_exact(peer, 4)
            if (version, command, reserved) != (5, 1, 0):
                raise RuntimeError("Invalid RFC1928 CONNECT header")
            if kind == 1:
                address = socket.inet_ntop(socket.AF_INET, receive_exact(peer, 4))
            elif kind == 3:
                count = receive_exact(peer, 1)[0]
                address = receive_exact(peer, count).decode("ascii")
            else:
                raise RuntimeError("Expected IPv4 or opted-in remote DNS only")
            port = int.from_bytes(receive_exact(peer, 2), "big")
            if address != expected_host or port != 443:
                raise RuntimeError("Target name/address or port leaked/rewritten")
            peer.sendall(b"\x05\x00\x00\x01\x7f\x00\x00\x01\x01\xbb")
            print(json.dumps({
                "mode": "success", "target": address, "atyp": kind,
                "port": port, "authenticated": True,
            }), flush=True)
            time.sleep(0.15)


if __name__ == "__main__":
    try:
        main()
    except (OSError, ValueError, RuntimeError, ssl.SSLError) as error:
        print(f"INDEPENDENT SOCKS5 PEER FAILED: {error}", file=sys.stderr)
        sys.exit(1)
