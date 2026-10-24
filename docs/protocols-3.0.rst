HTTP and WebSocket protocols in Runwire 3.0
===========================================

Runwire 2.1.2 is published stable. This documents 3.0 development code.

Incoming H1 and outgoing H1 wire components
-------------------------------------------

Incoming HTTP/1.1 enforces bounds for request lines, headers, chunks,
body size, parser budgets and connection idle timeout. The development
Http1ClientRequestWriter and Http1ClientResponseParser handle
incremental bodies, chunked responses, trailers and malformed
messages. The branch now also contains experimental ``Http1ConnectedExchange`` which streams one request and one response over an already verified established TCP/TLS connection; it offers no DNS dialing, redirecting or active pooling. It releases a fully framed, empty keep-alive socket only after response completion. A complete ``ClientExchangeInterface`` implementation, H2/H3 clients and verified pooling remain open.

.. code-block:: console

   vendor/bin/pest tests/Http1ConnectionTest.php tests/Http1ClientWireTest.php

HTTP/2 server and HPACK
-----------------------

The H2 server has one connection flow-controller and scheduler,
bounded HPACK tables, header blocks, per-stream body queues,
RST_STREAM and GOAWAY. Outbound H2 and H2 extended CONNECT
WebSocket are not available.

.. code-block:: console

   vendor/bin/pest tests/Http2ConnectionTest.php tests/Http2AbuseTest.php

HTTP/3 and QUIC server
----------------------

Incoming H3 uses optional native QUIC TLS 1.3, control streams and
bounded QPACK blocked-field queues. Independent aioquic peer tests
are in the Security and Standards workflow. Outbound QUIC worker
streams alone do not constitute an H3 request client. Native
datagrams, path migration and application early 0-RTT are unsupported.

.. code-block:: console

   vendor/bin/pest tests/Http3SessionTest.php tests/Http3AbuseFaultAcceptanceTest.php

WebSocket and WebTransport status
---------------------------------

Inbound HTTP/1.1 WebSocket upgrade validates request headers, masking,
bounded fragmentation, UTF-8, ping/pong, close and session ownership.
The unreleased branch also has an **experimental** outbound
``WebSocketClient::connect()`` for ws/wss over the verified nonblocking
Happy Eyeballs dialer. It validates an HTTP/1.1 101 response and
subprotocol, preserves coalesced frames, and uses the same WebSocket
session lifecycle with client masking. This work is **QA pending** and
not yet a 3.0 acceptance or production support guarantee.
HTTP/2 and HTTP/3 extended CONNECT and WebTransport client/server
are not implemented.

.. code-block:: console

   vendor/bin/pest tests/WebSocketIntegrationTest.php tests/WebSocketFrameParserTest.php
   vendor/bin/pest tests/WebSocketOutboundClientTest.php tests/WebSocketOutboundTlsTest.php
   vendor/bin/pest tests/WebSocketConcurrentSessionsTest.php tests/WebSocketBackpressureAcceptanceTest.php

The webtransport-design-3.0 document records planned session ownership.

Budgets, cancellation and safety
--------------------------------

One established connection has one active read/cancellation owner.
Enforce headers, HPACK/QPACK, blocked streams, body and write-queue
budgets. Session-fatal protocol violations terminate the session;
stream-local errors must not corrupt siblings. Never silently
replay unsafe methods or forward credentials across origins.
