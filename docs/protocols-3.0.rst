HTTP and WebSocket protocols in Runwire 3.0
===========================================

Runwire 2.1.2 is published stable. This documents 3.0 development code.

Incoming H1 and outgoing H1 wire components
-------------------------------------------

Incoming HTTP/1.1 enforces bounds for request lines, headers, chunks,
body size, parser budgets and connection idle timeout. The development
Http1ClientRequestWriter and Http1ClientResponseParser handle
incremental bodies, chunked responses, trailers and malformed
messages. The branch now also contains experimental ``Http1ConnectedExchange`` which streams one request and one response over an already verified established TCP/TLS connection; it offers no DNS dialing, redirecting or active pooling. It releases a fully framed, empty keep-alive socket only after response completion. The experimental ``Http1OutboundClient`` now connects via the shared off-loop DNS and verified Happy Eyeballs dialer, enforcing exact origin TLS identity and HTTP/1.1 ALPN; independent numeric TCP response tests are present. The opt-in ``Http1PolicyClient`` enforces bodyless GET/HEAD redirects, bounded retries, and cross-origin secret stripping. ``BoundedConnectionPool`` supports isolated lease admission and idle reuse but is not automatically wired into the client. The unreleased H2 client now has one-shot and live shared-connection paths;
Native H3 request clients are implemented and independently tested; an automatic unified H1/H2/H3 session pool or transparent H3 downgrade is not advertised.

.. code-block:: console

   vendor/bin/pest tests/Http1ConnectionTest.php tests/Http1ClientWireTest.php

HTTP/2 server and HPACK
-----------------------

The H2 server has one connection flow-controller and scheduler,
bounded HPACK tables, header blocks, per-stream body queues,
RST_STREAM and GOAWAY. The experimental internal
``Http2ClientHandshake`` validates server SETTINGS, generates an exact
client preface, bounded bodyless HPACK request HEADERS and connection
PING/GOAWAY responses. Independent reference tests now exercise a verified
TLS/ALPN H2 client, concurrent streams, received flow credit, cancellation
and CONTINUATION handling. H2 extended CONNECT WebSocket remains open. The new
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
The single-request H2 client deliberately does not multiplex, while the
separate verified shared-session transport does. H2 server push, extended
CONNECT and H3 extended CONNECT remain outside these development client contracts.
An independent negotiated H1/H2 client supports **explicit** TLS fallback.

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

WebSocket status
----------------

Inbound HTTP/1.1 WebSocket upgrade validates request headers, masking,
bounded fragmentation, UTF-8, ping/pong, close and session ownership.
The unreleased branch also has an **experimental** outbound
``WebSocketClient::connect()`` for ws/wss over the verified nonblocking
Happy Eyeballs dialer. It validates an HTTP/1.1 101 response and
subprotocol, preserves coalesced frames, and uses the same WebSocket
session lifecycle with client masking. Real independent WSS/close and 128 concurrent-peer fragmentation and
heartbeat regression suites have passed the branch PHPForge matrices;
this remains unreleased 3.0 development rather than production support.
HTTP/2 and HTTP/3 extended CONNECT are not implemented.
For excluded protocols and native QUIC constraints, see the limitations
in :doc:`features`.

.. code-block:: console

   vendor/bin/pest tests/WebSocketIntegrationTest.php tests/WebSocketFrameParserTest.php
   vendor/bin/pest tests/WebSocketOutboundClientTest.php tests/WebSocketOutboundTlsTest.php
   vendor/bin/pest tests/WebSocketConcurrentSessionsTest.php tests/WebSocketBackpressureAcceptanceTest.php
   vendor/bin/pest tests/WebSocketDeterministicFuzzTest.php

Budgets, cancellation and safety
--------------------------------

One established connection has one active read/cancellation owner.
Enforce headers, HPACK/QPACK, blocked streams, body and write-queue
budgets. Session-fatal protocol violations terminate the session;
stream-local errors must not corrupt siblings. Never silently
replay unsafe methods or forward credentials across origins.

HTTP/2 client stream-state groundwork
-------------------------------------

The internal H2 response decoder accepts validated odd client stream IDs
with bounded header/body memory and isolated terminal state. The distinct
multiplexed protocol and transport owners are implemented and exercised
against a real external TLS/hyper-h2 peer; existing one-shot clients remain
one-shot. Independent tests cover concurrent scheduling, GOAWAY, RST_STREAM
and outbound WINDOW_UPDATE credit. The internal
``Http2ClientMultiplexSession`` is a protocol-only preparation: it accepts
bounded concurrent same-origin GET/HEAD streams, reuses one HPACK decoder,
prevents cross-stream CONTINUATION interleaving and isolates client CANCEL,
peer RST and GOAWAY. **It does not itself own a socket, TLS identity, deadline, write budget
or event loop.** The separate Http2MultiplexConnection supplies that
callback/transport integration. Automatic cross-protocol HTTP pooling remains out of scope, while independently qualified five-pair H2/WS and full mixed 1800s gates have accepted release evidence.

.. code-block:: console

   vendor/bin/pest tests/Http2ClientMultiplexSessionTest.php


Live HTTP/2 multiplexer transport groundwork
--------------------------------------------

The experimental ``Http2MultiplexConnection`` now binds the connection-scoped
protocol session to one caller-supplied, separately verified TLS+ALPN ``h2``
stream. Its callbacks admit simultaneous same-origin bodyless requests after
server SETTINGS, isolate stream cancellation and response completion, and
cap outgoing control buffers, deadlines and active slots. A live socket-pair
fixture verifies sibling H2 exchanges and client RST handling without
pretending the fixture performs TLS verification. The independent
``hyper-h2`` TLS suite separately verifies the real shared-connection
multiplexer with reversed responses, large flow windows, authenticated
cancellation and slow sibling completion. U07.05's development acceptance
is covered; full automatic pooling, H3 and frozen-release performance
certification remain separate gates. This is unreleased 3.0 code.

.. code-block:: console

   vendor/bin/pest tests/Http2MultiplexConnectionTest.php

Independent concurrent TLS HTTP/2 interoperability
--------------------------------------------------

A separate, mandatory ``HTTP/2 Independent Reference`` PR workflow executes
``qa/reference/Http2MultiplexIndependentReferenceTest.php`` on PHP 8.4 and 8.5
against upstream ``hyper-h2``. The peer verifies one real TLS/ALPN socket,
two concurrent request streams and reversed responses with connection-
scoped HPACK. The exact W96 independent reference and PHPForge source suites
passed on PHP 8.4/8.5; real canceled streams, bounded 64-cycle churn,
slow peers and saturated flow windows are also covered. Full frozen-release
fault and resource certification remain U15/U17 responsibilities.


HTTP/2 receive-window stress and canceled streams
-------------------------------------------------

The same mandatory independent TLS/hyper-h2 reference now has an additional
flow-pressure mode: each of two simultaneously live streams carries 131072
bytes, deliberately exceeding the default 65535-byte connection and stream
receive windows. Peer completion therefore tests credit replenishment and
sibling fairness rather than merely testing short responses. A separate
64-generation protocol regression exercises stream-local RST, pending peer
acknowledgments, exactly-once completion and the fixed retired-stream budget.
These development tests satisfy the development flow/cancel acceptance,
not production benchmarking, H3 availability or full release certification.


Explicit outbound H1/H2 ALPN selection
--------------------------------------

The experimental ``HttpNegotiatingOutboundClient`` uses one existing outbound
verified TLS connection and selects an H1 or H2 exchange only after actual
ALPN negotiation. It advertises only the caller-approved HTTP/1.1/H2 versions,
preserves the original certificate peer identity, and requires explicit
fallback permission before H2 can become HTTP/1.1. HTTP/3 is deliberately
rejected rather than treating an absent outbound QUIC client as permission to
downgrade. Plain HTTP remains a separate, explicit H1-only opt-in. Independent
TLS reference fixtures offer H2 and H1 individually to exercise selection,
not mock negotiation. Exact-source negotiated TLS H1/H2 QA is accepted. Native HTTP/3 is a separately qualified QUIC client; no unified H3/H2/H1 automatic fallback is promised.

The independent TLS reference also probes a strict H2 client against a
peer advertising only HTTP/1.1: no HTTP request may be emitted during a
forbidden ALPN downgrade. This is separate from the explicitly permitted H1
fallback case; both are mandatory CI cases before U07.08 acceptance.


Authenticated HTTP/2 stream cancellation interoperability
---------------------------------------------------------

The mandatory independent ``hyper-h2`` TLS workflow additionally checks a
live pair of accepted requests where Runwire cancels stream one with
``RST_STREAM`` and completes stream three over the same verified connection.
This supplements deterministic 64-generation cancellation tests and the
large-body flow-window workload. Exact-head full quality and sustained
This real TLS acceptance is bounded to U07.05; release soak is separate.


Independent stalled-sibling HTTP/2 acceptance
---------------------------------------------

The TLS reference server can now withhold completion of one H2 response while
completing the other over the same verified connection, then finish the
initially stalled stream. This mode is mandatory beside the independent
out-of-order response, 131072-byte flow-window, and authenticated RST modes.
It tests stream-local fairness and cancellation, not the final multi-hour
release performance and leak certification. A new exact-source QA run is
required for ongoing release hardening beyond accepted U07.05 work.
