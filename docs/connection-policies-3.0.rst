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

A relay will need independent bounded read and write queues,
symmetric high/low watermarks, half-close semantics and explicit
scope cancellation. Closing one write direction does not require
prematurely terminating the reverse side; a shutdown deadline must
close both directions and retire watchers. No high-throughput
TCP/Unix relay is implemented or certified yet.

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
