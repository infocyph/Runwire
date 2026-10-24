Pool, proxy, relay and credential boundaries (Runwire 3.0)
==========================================================

This is unreleased Runwire 3.0 development documentation, not
production certification for client pools or proxy connectors.
The branch includes bounded H1 connections, a constrained experimental
H2 one-shot TLS client, idle pool admission and TCP/Unix relay primitives;
active H2 multiplex reuse and CONNECT/SOCKS5 remain unfinished.

Connection identity and reuse
-----------------------------

PoolIsolationKey binds the exact destination host/port/transport,
worker or tenant scope, TLS peer identity, caller-revisioned trust
roots, client identity and ALPN, and optional proxy route and
authentication policy revision. Separate origin, protocol, credential
or authority contexts must never share a pooled connection. Its
opaque digest is not a secret and does not authorize reuse.

An experimental ``BoundedConnectionPool`` now adds finite active lease
slots, FIFO waiter admission, explicit waiter timeouts/cancellation, and
caller-owned release of successful or failed dials. A null connection
with no error grants a reserved dial slot; it is NOT a dial itself.
There is still no automatic connection dialing, no HTTP/2 or HTTP/3
multiplex slot management, and no GOAWAY-aware reuse.
An experimental ``IdleConnectionPool`` can retain a finite number of
already verified, fully quiescent streams for a finite TTL, in separate
``PoolIsolationKey`` identity buckets. It installs its own exclusive
callback owner to evict any unsolicited inbound bytes, peer EOF or closed
socket and never hands such sockets back to a caller. Global capacity
pressure evicts the oldest idle socket. It does not establish connections,
queue waiters, certify HTTP response framing or offer an HTTP client.
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

The experimental cleartext TCP/Unix relay now allows the reverse stream
to survive peer FIN. The output half-close occurs only after accepted
bytes drain, without busy retries, and both sides close when finished.
Cancellation and deadline still abort both sides immediately. Raw
``shutdown(SHUT_WR)`` is not an authenticated TLS ``close_notify``;
encrypted relays retain fail-closed EOF behavior. The scoped cleartext
half-close and cancellation task U10.08 was QA-accepted at 3e31268;
TLS close-notify remains outside its verified scope.

.. code-block:: console

   vendor/bin/pest tests/StreamRelayTest.php tests/StreamRelayLargeTransferTest.php
   vendor/bin/pest tests/StreamRelayHalfCloseTest.php tests/BoundedConnectionPoolTest.php

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

Active pool isolation caveats
-----------------------------

``BoundedConnectionPool`` provides a global active lease quota (including
pending dial permits), finite FIFO waiters and a separate idle-socket cap
with TTL. Waiters retire on deadline, explicit cancel or pool shutdown.
Returning a connection through ``release()`` runs idle safety and the
original scoped ``PoolIsolationKey`` check. A failed dial MUST release its
permit with a null connection to admit the next queued waiter; otherwise
the caller, not the pool, owns that active permit indefinitely. Active
leases are never silently taken away from callers, and pool shutdown
cannot force-close a socket already issued to another owner. This is
necessary request-isolation behavior, not a finished high-level HTTP
client, connector, proxy stack or multiplex scheduler.

Explicit H1 redirect and retry policy (development)
---------------------------------------------------

The opt-in ``ClientRedirectRetryPolicy`` and ``Http1PolicyClient`` run
bodyless GET/HEAD across finite hops and transport retries. HTTP redirects
cannot silently replay POST/streaming uploads, downgrade HTTPS to HTTP or
forward Authorization, Cookie, Proxy-Authorization, Host or private custom
headers across another origin, including a changed port. All redirects
require an explicit hop ceiling, cross-origin targets require an explicit
policy, and supplied TLS trust policies are never carried to an unrelated
origin. Intermediate response bodies are consumed but hidden from the
application. The runner uses independent verified H1 connections and
closes intermediate sockets; it does not implement H2/H3, automatic cookie
management, a generalized proxy connector or safe retry of visible replies.

.. code-block:: console

   vendor/bin/pest tests/ClientRedirectRetryPolicyTest.php tests/Http1PolicyClientTest.php

The bounded H1 redirect/retry and proxy-secret isolation tasks U07.09 and
U14.05 were QA-accepted at 3e31268. This does not certify transparent H2/H3
redirects, authenticated proxy connectors or a shared cookie jar.

Direct origin proxy-secret boundary
-----------------------------------

The direct-origin ``Http1ClientRequestWriter`` now explicitly rejects
``Proxy-Authorization`` and ``Proxy-Connection`` both in outbound
request headers and chunk trailers, before serializing a wire request.
A future authenticated CONNECT tunnel must manage proxy-only credentials
separately and never reuse those fields in an origin request. Tests prove
that cross-origin redirects also strip Authorization, Cookie, arbitrary
private headers and proxy-only secrets. This does **not** certify a
proxy connector or proxy credential encryption implementation.
