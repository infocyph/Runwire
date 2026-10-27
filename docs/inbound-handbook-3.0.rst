Inbound servers: HTTP, TCP, Unix, UDP and WebSocket
===================================================

Overview and supported transport boundary
-----------------------------------------

Runwire accepts TCP byte streams, Unix-domain byte streams and UDP
datagrams, and can serve HTTP/1.1, HTTP/2, optional HTTP/3 over native
QUIC and HTTP/1.1 WebSocket upgrades. Each transport has distinct
message boundaries, admission costs and shutdown behavior. WebTransport
is **excluded** from 3.0 and is not implemented by relabeling QUIC
streams or ordinary UDP datagrams.

The common developer entry is `Runtime::create()->listen()->run()`,
with `Server` for HTTP, `StreamServer` for framed TCP/Unix services,
and `DatagramServer` for UDP. The authoritative runnable example is
`tests/fixtures/portable_native_acceptance.php`.

HTTP server startup
-------------------

.. code-block:: php

   <?php
   use Infocyph\Runwire\Runtime;
   use Infocyph\Runwire\Server;

   $server = Server::http('127.0.0.1:8080', $requestHandler, 'api');
   $runtime = Runtime::create();
   $runtime->listen($server)->run();

`$requestHandler` is an application-supplied handler obeying Runwire's
request and response-writer contract. Always complete or explicitly
cancel the response writer; merely returning from a callback does not
prove that its queued wire bytes were delivered. Source-backed handler
examples are in `tests/fixtures/portable_native_acceptance.php` and
the :doc:`api-reference-3.0` declaration inventory.

Binding addresses and port ownership
------------------------------------

Bind to loopback during local development. Production bind addresses
are operator decisions: `0.0.0.0` exposes the interface to the network,
while loopback allows reverse-proxy isolation. Verify dual-stack IPv6
behavior; do not infer IPv6 availability from IPv4 success. Unix socket
paths require permissions, cleanup and an explicit serving user/group.
For prefork workers, distinguish a listener bound before fork from a
listener already started on a specific reactor. An active inherited
listener must not be manipulated from the wrong PID.

HTTP/1.1 request handling
-------------------------

HTTP/1.1 framing requires bounded request lines, header fields, chunk
metadata and message bodies. A slow client is not free: each partially
received message occupies buffers, timers and often a connection slot.
Reject oversized or malformed data before it reaches the application.
Honor HTTP keep-alive only when parser and response framing are complete.
Never reuse a connection with unread bytes, ambiguous length, incomplete
trailers or an unexpected protocol upgrade.

In the 3.0 tree, HTTP/1.1 connection and parser coverage is available
through:

.. code-block:: console

   vendor/bin/pest tests/Http1ConnectionTest.php tests/Http1ClientWireTest.php

HTTP/2 server and streaming
---------------------------

HTTP/2 multiplexes request streams over a connection; do not equate
one socket with one request. The server owns one HPACK decode context
and stream registry per connection. Flow-control updates must follow
real byte consumption rather than speculative acceptance. Independent
RST_STREAM and GOAWAY handling should not corrupt sibling streams.

Graceful drain sends GOAWAY with a last accepted stream ID, stops new
stream admission and allows already accepted responses to finish within
a bounded policy. Cancellation of one stream does not imply aborting the
entire shared connection. Check the independent reference workflow for
real hyper-h2 behavior rather than relying only on self-generated frames.

.. code-block:: console

   vendor/bin/pest tests/Http2ConnectionTest.php tests/Http2AbuseTest.php

HTTP/3 and QUIC listeners
-------------------------

HTTP/3 runs on encrypted QUIC streams, not on raw TCP or unencrypted
UDP echo sockets. It needs an installed and capable native QUIC stack
with appropriate TLS/ALPN. H3 control streams, QPACK encoder/decoder
instructions, request-stream creation, flow credit, cancellation,
GOAWAY and connection retirement have independent lifetimes.

The same UDP port can serve QUIC packets, but ordinary
`DatagramServer` does not turn UDP payloads into an H3 connection.
Do not enable H3 by declaring a desired protocol string if the backend
cannot provide authenticated native QUIC sessions.

Run the native interoperability and hostile sibling-stream test matrix
in `.github/workflows/security-standards.yml`. Consult
:doc:`protocols-3.0` for QPACK and loss/reorder details.

WebSocket upgrades
------------------

The H1 upgrade is an explicit protocol transition: validate the method,
`Upgrade` and `Connection` headers, version/key, and any negotiated
subprotocol. WebSocket frames require correct masking by direction,
continuation/fragment handling, close-code validation, ping/pong and
message-size bounds. Backpressure applies to the WebSocket message
queue, not just the TCP write buffer.

The outbound WebSocket client is separate from the inbound upgrade.
H2/H3 extended CONNECT WebSocket is not promised by the 3.0
implementation; it must not be silently activated or documented as
passing based on HTTP/1.1 upgrade tests alone.

Framed TCP and Unix streams
---------------------------

A TCP read may yield fewer or more bytes than one logical application
message. `StreamServer` uses a framing codec contract to distinguish
complete application frames from partial transport reads. Application
handlers must not interpret one read callback as exactly one packet.
Apply maximum-frame and unread-buffer limits to hostile peers.

Unix sockets inherit filesystem permissions, path ownership and stale
socket cleanup concerns. A Unix-domain socket can serve local IPC; it
does not provide automatic authentication solely because it avoids an
IP address. Restrict path access and verify the connecting peer when
the application's trust model requires it.

UDP message processing
----------------------

Each datagram is an independent message. There is no ordered stream
state, no TCP-style retry or global connection close. Bound datagram
sizes, origin policy and per-peer ingress rates. Validate that the
application explicitly handles truncation, duplicate deliveries,
spoofed source addresses and datagram drops.

Real protocol acceptance
------------------------

.. code-block:: console

   php tests/fixtures/portable_native_acceptance.php
   php tests/fixtures/portable_optional_protocol_acceptance.php
   php .ci/c3_mixed_peer_smoke.php

The first command exercises portable HTTP/TCP/Unix/UDP. The second
tests conditional protocol behavior, including explicit unavailability.
The third requires native process capability and exercises mixed
protocol lifecycle under worker faults. Passing one does not imply the
others, and none replaces the frozen release-wide independent peer
certification.

Common inbound failures
-----------------------

.. list-table:: Symptom and first inspection
   :header-rows: 1
   :widths: 28 72

   * - Symptom
     - Check first
   * - Bind refused
     - Interface/port permissions, duplicate listener, Unix path owner
   * - TLS handshake abort
     - Server certificates, ALPN, trust configuration, handshake timeout
   * - Accepted H2 sibling lost
     - GOAWAY state, per-stream reset, output queue, drain timeout
   * - H3 unavailable
     - ext-quic/OpenSSL runtime capability and independent QUIC peer
   * - WebSocket closes after upgrade
     - Framing, direction mask, ping/pong, configured message ceiling
   * - High RSS during slow clients
     - Request limits, connection count, outstanding stream body queues
   * - Stale worker after reload
     - Readiness generation, stop reason, process reaping and grace deadline

Read :doc:`configuration-reference-3.0` for limits and
:doc:`supervision-handbook-3.0` for lifecycle details.
