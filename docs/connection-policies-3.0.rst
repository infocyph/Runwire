Pool, proxy, relay and credential boundaries (Runwire 3.0)
==========================================================

This is unreleased development documentation, not a feature list
of connected HTTP client pools, proxy clients or relays. At present,
only a constrained pool identity contract and outgoing TCP/Unix
dial and owner-transfer primitives are available.

Connection identity and reuse
-----------------------------

PoolIsolationKey binds the exact destination host/port/transport,
worker or tenant scope, TLS peer identity, caller-revisioned trust
roots, client identity and ALPN, and optional proxy route and
authentication policy revision. Separate origin, protocol, credential
or authority contexts must never share a pooled connection. Its
opaque digest is not a secret and does not authorize reuse.

There is currently NO active bounded pool, waiter queue, eviction,
HTTP/2 or HTTP/3 multiplex slot manager or GOAWAY-aware reuse.
The development stream provides ``Connection::isPoolIdleSafe()`` as a
fail-closed reuse preflight: it rejects unread response bytes, queued writes,
peer EOF, outstanding scope ownership, protocol callback claims and close
observers. This is a necessary condition only; the caller still must enforce
the exact scoped ``PoolIsolationKey``, negotiated ALPN and HTTP framing.
A reusable connection is not certified merely by this predicate.
Explicit ownership transfer can move one validated live connection
between cancellation scopes; it does not prove reusable HTTP framing
or guarantee the previous response body was fully drained.

HTTP CONNECT and SOCKS5
-----------------------

HTTP CONNECT, proxy authentication and SOCKS5 are separate unfinished
implementation gates. A future CONNECT must authenticate to the
configured proxy using a scoped policy, validate a bounded successful
tunnel response and then separately authenticate the original TLS
server identity *through* the tunnel. The proxy identity does not
replace the destination certificate. A failed proxy handshake must
close sockets and clear credentials; do not retry with plaintext.

A future SOCKS5 connector must explicitly choose remote versus local
DNS based on policy, reject unexpected authentication methods and
prevent leaking destination lookups when proxy-side DNS is required.
Never put proxy passwords into connection keys, diagnostic logs or
exception strings. The pool key only contains the caller-provided
opaque credential revision, not the credential itself.

Bidirectional stream relay
--------------------------

The 3.0 development branch now contains a preliminary
``Network\\StreamRelay`` for already-established TCP/Unix ``Connection``
pairs. It takes exclusive protocol callback ownership of both connections,
forwards bounded chunks using the existing receive/send queues and
high/low watermarks, suspends upstream transport reads on downstream
pressure and resumes on drain. Independent Unix stream fixtures exercise
both directions and resource cleanup; a separate 1 MiB SHA-256 fixture
tests a temporarily stalled receiver with 64 KiB send/receive caps.

Optional ``CancellationToken`` and monotonic ``RequestDeadline`` now close both relay directions and retire timer/subscription ownership, with real stream tests. 

This is **not** a certified release-ready relay: EOF currently aborts
both sides. Correct half-close semantics, request cancellation,
shutdown deadlines and drain-after-peer-EOF belong to the remaining
U10.08 gate. In particular, do not use this primitive yet for a
production tunnel requiring the reverse stream to outlive a half-close.

.. code-block:: console

   vendor/bin/pest tests/StreamRelayTest.php tests/StreamRelayLargeTransferTest.php

Outbound trust and SSRF guard
-----------------------------

TlsClientOptions requires certificate chain, hostname/SNI and ALPN
verification. Client TLS is never silently downgraded to plaintext
after failure; cross-origin credentials must not follow redirects.

The opt-in OutboundAddressPolicy enforces public-IP-only numeric
candidates before TCP socket creation and compares the actual
connected peer against that approved IP before TLS or ownership
transfer. It does not implement a redirect policy, allow internal
services, or act as a filesystem path authorization for Unix dials.
Applications are responsible for an explicit trusted endpoint policy.

Runnable focused regressions
----------------------------

.. code-block:: console

   vendor/bin/pest tests/PoolIsolationKeyTest.php tests/OutboundAddressPolicyTest.php
   vendor/bin/pest tests/TlsClientDialerTest.php tests/ExtendedConnectRejectionTest.php

HTTP/2 and HTTP/3 extended CONNECT for WebSocket/WebTransport are
explicitly unsupported; the shared request-header validator rejects
the unrecognized protocol pseudo-header rather than silently
advertising unsupported protocol variants. Ordinary CONNECT
parsing does not constitute successful proxy negotiation.
