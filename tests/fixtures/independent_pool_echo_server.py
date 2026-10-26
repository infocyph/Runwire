#!/usr/bin/env python3
"""Independent persistent TCP echo server for bounded Runwire pool measurements."""

import socketserver


class EchoHandler(socketserver.StreamRequestHandler):
    def handle(self):
        self.connection.settimeout(10.0)
        while True:
            line = self.rfile.readline(64)
            if not line:
                return
            if line != b"ping\n":
                return
            self.wfile.write(b"pong\n")
            self.wfile.flush()


class EchoServer(socketserver.ThreadingMixIn, socketserver.TCPServer):
    allow_reuse_address = True
    daemon_threads = True


if __name__ == "__main__":
    with EchoServer(("127.0.0.1", 0), EchoHandler) as server:
        print(f"READY {server.server_address[1]}", flush=True)
        server.serve_forever(poll_interval=0.05)
