Protocol failures, admission and graceful drain
===============================================

What counts as a successful request?
-------------------------------------

Attempt, transport establishment, server admission, response commit
and full response completion are **five different checkpoints**.
A successful client-side socket write is not evidence that the server
admitted the request. A completed server handler is not evidence
that the peer received the full body.

.. list-table:: Protocol-specific correctness boundaries
   :header-rows: 1
   :widths: 19 34 47

   * - Protocol
     - Admission and completed work
     - Shutdown/failure boundary
   * - HTTP/1.1
     - Fully framed headers and body with bounded dispatch
     - Stop accepting; finish framed output; do not reuse after ambiguous EOF
   * - HTTP/2
     - Accepted stream/header block, sibling response bodies
     - GOAWAY, flow control, stream resets and drain deadlines
   * - HTTP/3
     - Native QUIC stream, valid H3/QPACK header state
     - Stop stream admission, preserve siblings; QUIC backend required
   * - H1 WebSocket
     - Valid Upgrade/101 followed by framed session
     - Masking, close handshake, ping/pong and bounded pending output
   * - TCP / Unix
     - Accepted connection and application-specific framed message
     - Half-close only after already queued bytes have drained
   * - UDP
     - One accepted datagram, no ordered byte stream
     - No implicit delivery, retry, deduplication or stream drain

HTTP/1.1 and HTTP/2 are **not** interchangeable retry transports.
HTTP/3 is QUIC over UDP, not UDP made to look like HTTP.
WebTransport and H2/H3 extended-CONNECT WebSocket are outside this
3.0 scope.

HTTP/1.1: partial reads and pooling
------------------------------------

Headers may arrive across multiple TCP reads. Enforce header/body
budgets before building application objects. A client that disconnects
after sending headers can leave an application request in flight;
propagate its cancellation to the request owner.

An outbound H1 connection is eligible for pooling only when response
framing is complete and the peer identity and route policy still match.
EOF that makes body length ambiguous poisons the connection for reuse.
Do not forward Proxy-Authorization or tenant cookies across origin
changes.

HTTP/2: accepted siblings through drain
-----------------------------------------

One TCP/TLS connection carries many HTTP/2 streams. Stop accepting
new stream work, send the correct GOAWAY boundary, and drain previously
admitted streams without discarding unrelated siblings. A reset of
one client stream must not close a healthy response stream.

A robust native SIGTERM probe waits for **server-issued response
headers** on each of eight H2 streams, not merely the client's send
completion. It then slows flow-control updates while shutdown begins
and verifies each admitted body byte-for-byte. Inspect
`benchmarks/mixed_native_lifecycle.py` and the independently executed
H2 reference workflow for this behavior.

Failure triage:

#. Did TLS negotiate `h2` ALPN and preserve origin identity?
#. Which last client-initiated stream ID did the server accept?
#. Did GOAWAY arrive, and which stream IDs were beyond its boundary?
#. Did the canceled ninth test stream wrongly affect eight siblings?
#. Was flow control replenished or did output stall?
#. Did a graceful deadline expire into force-stop?
#. Is the error a reset, premature EOF or true response-body mismatch?

HTTP/3: QUIC, QPACK and stream ownership
-----------------------------------------

H3 requests use QUIC streams with independent stream and connection
limits. QPACK encoder/decoder control streams and blocked header state
require separately bounded ownership and cleanup. Resetting a request
cannot implicitly cancel valid sibling streams.

Use an independent aioquic peer and real QUIC capability tests.
An absent ext-quic/OpenSSL backend is a **capability failure**, not
proof the H3 application protocol is broken. A successful ordinary
UDP packet does not establish a QUIC/TLS session.

WebTransport is explicitly excluded. There is no RFC 9221
DATAGRAM feature being advertised as WebTransport, even if
ordinary UDP datagrams and QUIC request streams work independently.

WebSocket: handshake versus session lifetime
---------------------------------------------

A valid WebSocket handshake requires the expected Upgrade/101
response and agreement on selected subprotocol. WSS additionally
requires full certificate and hostname verification. The session
must enforce masking, frame lengths, fragmentation, close controls
and outbound buffer ceilings. A completed 101 handshake does not
mean that application frame delivery will continue after process stop.

TCP half-close and Unix-domain permissions
--------------------------------------------

EOF in one direction is not an immediate instruction to discard
accepted output in both directions. Keep ownership of queued writes
until they drain, then propagate FIN while the opposite direction
continues under its own bounds. TLS EOF must not be mistaken for
an authenticated close_notify. Unix socket endpoints also require
path permissions and stale-path handling.

UDP: no hidden reliability
---------------------------

A UDP listener accepts datagrams, not a stream. Send success means
the local transport accepted a datagram. Delivery, order,
retransmission and deduplication require an upper-layer contract.
Enforce packet size ceilings and avoid fabricating a FIN or a
reliable response primitive on raw UDP.

TLS, proxy and SSRF failure matrix
-----------------------------------

.. list-table:: Fail-closed requirements
   :header-rows: 1
   :widths: 36 64

   * - Scenario
     - Required behavior
   * - Cleartext CONNECT tunnel to HTTPS origin
     - Validate the **origin** certificate and ALPN inside the tunnel
   * - HTTPS proxy with HTTPS origin
     - Reject unsupported nested TLS; never call it successful
   * - Blocked resolved/connected IP
     - Reject according to SSRF policy before using endpoint
   * - Changed origin during redirect
     - Re-evaluate authorization, cookies and proxy credential isolation
   * - Peer hostname or certificate mismatch
     - Fail the handshake; do not silently downgrade to plaintext
   * - Untrusted mutable filesystem path
     - Require OS isolation instead of race-prone PHP string guards

Signal semantics and retry policy
----------------------------------

.. code-block:: text

   Socket attempt fails before request admission
      -> classify DNS/connect/TLS; retry only if explicit policy permits.

   Request accepted, complete verified response received
      -> count success, retire request ownership.

   Accepted request incomplete or response prematurely closed
      -> count failure; never silently retry a side-effecting request.

   Graceful stop
      -> block new admission, drain accepted responses within deadline.

   SIGKILL or forced stop
      -> immediate termination; in-flight work may be lost.

The native integration checks exercise process failures, autoscaling
and cleanup with real peers:

.. code-block:: console

   php tests/smoke/native_mixed_peer_smoke.php
   php tests/smoke/native_concurrent_fault_smoke.php
   php tests/smoke/native_cleanup_fault_smoke.php

For independently validated H3 and H2, use their CI jobs in an
eligible native capability environment. Include source SHA, PID,
worker generation, protocol, stream IDs, server admission evidence
and actual received response bytes when filing a bug.

See :doc:`runtime-lifecycle-3.0`, :doc:`security-handbook-3.0`
and :doc:`troubleshooting-handbook-3.0`.
