#!/usr/bin/env python3
"""Independent TLS/ALPN HTTP/1.1 and HTTP/2 comparison reference peer."""
import asyncio
import ssl
import sys
from pathlib import Path
from h2.config import H2Configuration
from h2.connection import H2Connection
from h2.events import RequestReceived

BODY = b"runwire-outbound-h3-peer-ok"

async def handle(reader, writer):
    try:
        proto = writer.get_extra_info("ssl_object").selected_alpn_protocol()
        if proto == "http/1.1":
            head = await asyncio.wait_for(reader.readuntil(b"\r\n\r\n"), 5)
            if b"GET /interop?q=1 HTTP/1.1" not in head:
                raise ValueError("Unexpected H1 request")
            writer.write(b"HTTP/1.1 200 OK\r\ncontent-type: text/plain\r\ncontent-length: "
                         + str(len(BODY)).encode() + b"\r\nconnection: close\r\n\r\n" + BODY)
            await writer.drain()
        elif proto == "h2":
            h = H2Connection(H2Configuration(client_side=False, header_encoding="utf-8"))
            h.initiate_connection()
            writer.write(h.data_to_send())
            await writer.drain()
            done = False
            while not done:
                data = await asyncio.wait_for(reader.read(65535), 6)
                if not data:
                    raise ConnectionError("peer disconnected")
                for event in h.receive_data(data):
                    if isinstance(event, RequestReceived):
                        headers = dict(event.headers)
                        if headers.get(":method") != "GET" or headers.get(":path") != "/interop?q=1":
                            raise ValueError("Unexpected H2 request")
                        h.send_headers(event.stream_id, [
                            (":status", "200"), ("content-type", "text/plain"),
                            ("content-length", str(len(BODY)))])
                        h.send_data(event.stream_id, BODY, end_stream=True)
                        done = True
                writer.write(h.data_to_send())
                await writer.drain()
        else:
            raise ValueError("Missing negotiated H1/H2 ALPN")
    except (OSError, ConnectionError, ValueError, asyncio.TimeoutError):
        pass
    finally:
        writer.close()
        try:
            await writer.wait_closed()
        except OSError:
            pass

async def main():
    port, cert, key, ready = sys.argv[1:5]
    ctx = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
    ctx.load_cert_chain(cert, key)
    ctx.set_alpn_protocols(["h2", "http/1.1"])
    srv = await asyncio.start_server(handle, "127.0.0.1", int(port), ssl=ctx)
    Path(ready).write_text("ready", encoding="utf-8")
    async with srv:
        await srv.serve_forever()

if __name__ == "__main__":
    asyncio.run(main())
