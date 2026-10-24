HTTP and WebSocket protocols in Runwire 3.0
===========================================

Runwire 2.1.2 is published stable. This documents 3.0 development code.

Incoming H1 and outgoing H1 wire components
-------------------------------------------

Incoming HTTP/1.1 enforces bounds for request lines, headers, chunks,
body size, parser budgets and connection idle timeout. The development
Http1ClientRequestWriter and Http1ClientResponseParser handle
incremental bodies, chunked responses, trailers and malformed
messages. The branch now also contains experimental ``Http1ConnectedExchange`` which streams one request and one response over an already verified established TCP/TLS connection; it offers no DNS dialing, redirecting or active pooling. It releases a fully framed, empty keep-alive socket only after response completion. The experimental ``Http1OutboundClient`` now connects via the shared off-loop DNS and verified Happy Eyeballs dialer, enforcing exact origin TLS identity and HTTP/1.1 ALPN; independent numeric TCP response tests are present. The opt-in ``Http1PolicyClient`` enforces bodyless GET/HEAD redirects, bounded retries, and cross-origin secret stripping. ``BoundedConnectionPool`` supports isolated lease admission and idle reuse but is not automatically wired into the client. Full H2/H3 outbound clients and transparent multi-protocol negotiation remain open.

.. code-block:: console

   vendor/bin/pest tests/Http1ConnectionTest.php tests/Http1ClientWireTest.php

HTTP/2 server and HPACK
-----------------------

The H2 server has one connection flow-controller and scheduler,
bounded HPACK tables, header blocks, per-stream body queues,
RST_STREAM and GOAWAY. The experimental internal
``Http2ClientHandshake`` validates server SETTINGS, generates an exact
client preface, bounded bodyless HPACK request HEADERS and connection
PING/GOAWAY responses. This is **not** an established H2 client:
real TLS ALPN, multiplexed stream lifecycles, flow-control credit and
CONTINUATION remain open, as does H2 extended CONNECT WebSocket. The new
internal ``Http2ClientSession`` composes bounded single-stream response
HEADERS/CONTINUATION/HPACK and DATA callbacks with control-frame validation
and receive-window credit. The new experimental ``Http2ConnectedExchange``
exclusively owns one externally verified TLS+h2 connection, consumes bounded
response frames and cancels or aborts it on completion (no unsafe pooling).
``Http2OutboundClient`` adds independently verified TLS+h2-only dialing and
a one-shot GET/HEAD exchange; an independent local TLS peer fixture tests
a real certificate/ALPN handshake and frame response. A dedicated PR-only
``HTTP/2 Independent Reference`` workflow now validates this outbound client
against upstream Python ``hyper-h2`` under pinned 8.4/8.5 PHP matrices,
including verified CA/SAN/TLS, ALPN, HPACK HEADERS and streamed body.
**These additions
are QA-pending** and do not implement H2 multiplexing, server push, trailers,
transparent H1 fallback, a complete H2 client, or H3/WT.

.. code-block:: console

   vendor/bin/pest tests/Http2ConnectionTest.php tests/Http2AbuseTest.php
   vendor/bin/pest tests/Http2ClientHandshakeTest.php tests/Http2ClientResponseParserTest.php
   vendor/bin/pest tests/Http2ClientSessionTest.php tests/Http2ConnectedExchangeTest.php
   vendor/bin/pest tests/Http2OutboundClientTest.php tests/Http2ConnectedExchangeCancellationTest.php
   vendor/bin/pest --configuration=pest.xml qa/reference/Http2IndependentReferenceTest.php

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
session lifecycle with client masking. Real independent WSS/close and 128 concurrent-peer fragmentation and
heartbeat regression suites have passed the branch PHPForge matrices;
this remains unreleased 3.0 development rather than production support.
HTTP/2 and HTTP/3 extended CONNECT and WebTransport client/server
are not implemented.

.. code-block:: console

   vendor/bin/pest tests/WebSocketIntegrationTest.php tests/WebSocketFrameParserTest.php
   vendor/bin/pest tests/WebSocketOutboundClientTest.php tests/WebSocketOutboundTlsTest.php
   vendor/bin/pest tests/WebSocketConcurrentSessionsTest.php tests/WebSocketBackpressureAcceptanceTest.php
   vendor/bin/pest tests/WebSocketDeterministicFuzzTest.php

The webtransport-design-3.0 document records planned session ownership.

Budgets, cancellation and safety
--------------------------------

One established connection has one active read/cancellation owner.
Enforce headers, HPACK/QPACK, blocked streams, body and write-queue
budgets. Session-fatal protocol violations terminate the session;
stream-local errors must not corrupt siblings. Never silently
replay unsafe methods or forward credentials across origins.

HTTP/2 client stream-state groundwork
-------------------------------------

The internal H2 response decoder now accepts a validated, odd client-initiated
stream identifier, with individually bounded header/body memory and terminal
state. This prepares sibling-stream isolation without silently upgrading the
existing one-request outgoing connection into a multiplexed H2 client. Real
concurrent stream scheduling, GOAWAY / RST_STREAM recovery, outbound credit,
shared connection HPACK state and independent interop are still **pending**
under U07.05. Existing single-exchange public behavior remains unchanged.
