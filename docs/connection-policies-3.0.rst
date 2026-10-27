Pool, proxy, relay and credential boundaries (Runwire 3.0)
==========================================================

This is unreleased Runwire 3.0 development documentation, not
production certification for client pools or proxy connectors.
The branch includes bounded H1 connections, a constrained experimental
H2 one-shot TLS client, idle pool admission and TCP/Unix relay primitives;
automatic pool-backed H2 multiplex reuse remains unfinished; bounded
CONNECT/SOCKS5 negotiation has independently verified standalone adapters.

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
There is still no automatic connection dialing; H2/H3 admission and GOAWAY
handling exist but are not automatically integrated into pooled dialing.
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

Experimental nonblocking HTTP CONNECT and SOCKS5 adapters negotiate on a
previously established Runwire connection. Proxy credentials may be sent
only after the outbound Dialer verifies the proxy's TLS peer and that
identity matches the explicitly configured proxy hostname. Plaintext or
mismatched identities fail before transmitting credentials.

Successful proxy negotiation does NOT verify the original destination.
For **cleartext HTTP CONNECT**, ``Connection::upgradeToVerifiedTls()``
nonblockingly upgrades a quiescent tunnel with the original origin's
trusted roots, certificate-name/SNI and ALPN policy. Independent TLS peer
tests verify actual application bytes and reject the wrong origin name.

An **HTTPS proxy followed by an HTTPS origin** instead needs two distinct
TLS record layers. PHP's existing OpenSSL stream transport does not
expose a general second TLS layer over an already-encrypted stream.
The attempted nested upgrade is therefore explicitly rejected and
the proxy connection is aborted, preventing reuse as an origin-verified
stream or silent credential/verification downgrade. U10.06 remains
OPEN for full TLS-over-TLS acceptance; this fail-closed behavior is
not advertised as functional nested TLS. It does not remove the
successfully verified proxy identity or the independent origin check
required for future implementation.

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

HTTP/2 and HTTP/3 extended CONNECT are not advertised; the shared
request-header validator rejects unsupported ``:protocol`` values
rather than silently advertising unimplemented protocol variants. Ordinary CONNECT
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


Outbound proxy wire negotiation (unreleased)
--------------------------------------------

The Socks5Handshake codec validates RFC 1928/1929 frames. Local DNS mode
accepts only pre-resolved numeric IP addresses; remote hostname disclosure
requires explicit opt-in. The experimental Socks5Tunnel owns nonblocking
method negotiation, authenticated password exchange, finite timeouts,
cancellation and exclusive callback handoff. An authenticated method
substitution or failed RFC 1929 password check rejects without submitting
the target CONNECT request.

The HttpConnectHandshake codec bounds the HTTP/1.1 CONNECT authority and
200 response header. Its HttpConnectTunnel adapter supports Basic proxy
authentication only over a verified outbound TLS connection with a matching
proxy hostname. Invalid status, smuggling, framed response bodies and
untrusted peer identities fail closed. Data after a valid CONNECT response
remains available to the next protocol owner.

The standalone authenticated proxy CONNECT adapter and independent
reference-based proxy-leg TLS QA are accepted under U10.04. Independently
verified original-target TLS exists over a cleartext CONNECT tunnel,
but there is no automatic high-level proxied client or supported
TLS-over-TLS stream. U10.06 remains OPEN until the separately verified
nested case meets independent peer and complete QA requirements.

Real authenticated proxy tests
------------------------------

The local TLS loopback fixture runs separately handshaken client and server
TLS connections with an explicit trusted certificate. It checks CONNECT
Basic credentials, RFC 1929 username/password framing, failure response
handling, hostname mismatch before credential transmission, and callback
cleanup. Local fixtures do not substitute for third-party peer conformance.

.. code-block:: console

   vendor/bin/pest tests/ProxyAuthenticatedTlsInteropTest.php
   vendor/bin/pest tests/VerifiedOriginalTargetTlsInteropTest.php tests/VerifiedTargetTlsUpgradeGuardTest.php

Loopback TCP proxy peer verification (development)
--------------------------------------------------

The development fixture ``tests/ProxyTcpInteropTest.php`` uses a real TCP
listener and Runwire's asynchronous numeric TCP Dialer to test HTTP CONNECT
and SOCKS5 request/reply framing and tunneled data against a separate local
socket. This is a local peer, **not** an independent external proxy reference
implementation, and does not establish proxied TLS or authenticate to a proxy.


Origin target address authorization across proxies
--------------------------------------------------

Passing an ``OutboundAddressPolicy`` as ``targetAddressPolicy`` on the
experimental HTTP CONNECT or SOCKS5 tunnel enforces the same numeric public
IP restrictions **at the origin target**, not merely at the proxy endpoint.
Remote-DNS hostnames cannot prove their eventual resolved IP through that
boundary, so the restricted mode refuses them until a separately verified
resolved-target handoff is implemented. This is an optional fail-closed
policy, not proxy hostname authenticity or nested target TLS verification.


Proxy hostile-response matrix (development)
-------------------------------------------

The grouped W118 proxy reliability suite uses real Runwire socket-pair
connections to reject invalid HTTP CONNECT headers, SOCKS5 authentication
method substitution, malformed address replies, and oversized headers.
The tests assert no tunnel handoff and no retained loop watchers or timers.
They do not replace independent proxy-reference testing, nested target TLS
or verified proxy authentication acceptance.

Incomplete reply timeout semantics (development)
------------------------------------------------

SOCKS5 method selection and CONNECT replies, like HTTP CONNECT response
headers, are incremental: a short prefix is incomplete, not automatically
an invalid response. The grouped W121 tests exercise fragmented prefixes
through finite negotiation timeouts and require one terminal error with
no early successful tunnel handoff. Invalid complete frames remain
immediate failures.

Backpressured proxy connection ownership (development)
------------------------------------------------------

The grouped proxy lifecycle regression now exercises HTTP CONNECT and
SOCKS5 under one-byte-per-tick writes and a two-byte high watermark.
Drain callbacks inspect the live connection state rather than using
a no-op that conflicts with PHPForge Pint/Rector policies. The fixture
verifies full negotiation, application-byte handoff, pressure detection
and final watcher/timer cleanup. It does not prove end-to-end nested TLS.

Exclusive callback ownership on proxy failures
----------------------------------------------

CONNECT and SOCKS5 stages now check whether *their own adapter* actually
acquired the Runwire connection's callback slots before releasing slots or
aborting transport. A failed claim cannot close or revoke another adapter's
connection. Both protocols have live socket-pair regression coverage for
an already claimed connection and the original owner's successful
subsequent receive. The owner query reveals only whether the supplied
adapter is the owner, not another adapter's identity.
