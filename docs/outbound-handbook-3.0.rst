Outbound networking and HTTP clients
====================================

Separate dial, identity, protocol and ownership
-----------------------------------------------

Outbound connections require four independent decisions:

1. Which destination endpoint and IP addresses are allowed?
2. How is a nonblocking connection established and cancelled?
3. For TLS, which **original origin** and trust/ALPN policy must succeed?
4. Which application protocol owns the verified socket and when may it
   be reused?

Do not collapse these into a generic `connect()` that silently calls
blocking hostname resolution, drops TLS verification or retries a
mismatched origin over cleartext.

Numeric TCP and Unix dialing
----------------------------

`Infocyph\Runwire\Network\Dialer` uses loop-bound nonblocking
connection attempts for numeric TCP addresses and Unix-domain sockets.
`DialEndpoint` describes destination syntax, and `DialOptions` carries
timeouts, cancellation and optional verified TLS configuration. Each
callback completes once, and the caller owns a successfully handed-off
`Connection`. Pending count is bounded at construction.

.. code-block:: php

   <?php
   use Infocyph\Runwire\Loop\SelectLoop;
   use Infocyph\Runwire\Network\Dialer;

   $loop = new SelectLoop();
   $dialer = new Dialer($loop, maxPending: 16);
   $dialer->connect(
       'tcp://127.0.0.1:8080',
       static function ($connection, ?Throwable $error) use ($loop): void {
           if ($error !== null) {
               $loop->stop();
               return;
           }
           $connection->closeGracefully();
           $loop->stop();
       },
   );
   $loop->run();
   $dialer->close();

This is a sample of the ownership pattern, not a promise that a service
is listening at the example port. In production, propagate the error
to the calling scope, enforce a deadline, and handle cancellation
before making the connection available to other code.

Hostname DNS and Happy Eyeballs
-------------------------------

Name resolution is potentially blocking. The off-loop
`AsyncDnsResolver` works through bounded `ProcessExecutor` workers,
and `HappyEyeballsDialer` coordinates staggered verified IPv6/IPv4
attempts through the shared numeric `Dialer`.

For safe use, limit concurrent unresolved names, A/AAAA results and
outstanding address races. Distinguish timeout from NXDOMAIN, refused
connect and security rejection. Keep the original hostname even when
the actual socket reaches a numeric IP; TLS must verify the host the
application intended, not merely the resolved candidate.

Outbound TLS and HTTPS
----------------------

`TlsClientOptions` represents client-side origin verification, not a
server certificate context. The TLS handshake must validate the chain,
hostname and ALPN according to policy. Client authentication material
belongs to the explicit TLS identity. A failed certificate or peer-name
check must not cause fallback to HTTP or a different host.

A connection to an IP selected through DNS must still bind verification
to the original requested authority. Connection pooling must track
the same original origin and policy revision.

HTTP/1.1 outbound
-----------------

`Http1OutboundClient` performs a connected HTTP/1.1 exchange over
verified transport. Keep three separate layers in mind: framing,
connection creation and policy. `Http1ClientRequestWriter` and
`Http1ClientResponseParser` implement bounded wire operations.
`Http1PolicyClient` provides opt-in redirect/retry behavior for
supported bodyless methods; it is not a universal cookie jar or
transparent browser compatibility client.

Connection reuse is possible only after a fully framed response has
finished and the connection is safe for idle reuse. On ambiguous
framing, EOF or a protocol violation, close instead of pooling.

HTTP/2 outbound
---------------

`Http2OutboundClient` uses verified h2 ALPN and the HTTP/2 wire
contract. A single-request path is not automatically multiplexing.
The separate `Http2MultiplexConnection` supports shared-session
behavior subject to stream limits, flow credit, GOAWAY and
independently cancellable sibling requests. H2 server push and H2
WebSocket extended CONNECT are not silently supported.

Independent hyper-h2 reference tests provide stronger evidence than
connecting two Runwire implementations. Do not share one H2 session
across incompatible authority/trust contexts.

HTTP/3 outbound
---------------

`Http3OutboundClient` uses native QUIC/TLS/H3 capabilities. Stream IDs,
QPACK instructions, header admission and cancel/reset belong to their
own QUIC connection lifetime; an H3-only policy must fail closed when
native QUIC is missing. Never pretend that TCP fallback satisfied a
mandatory h3 request.

Use independent H3 peers and explicit packet loss/reorder tests for
verification. WebTransport and QUIC DATAGRAM-based WebTransport
semantics are excluded regardless of the availability of ordinary
UDP operations.

HTTP CONNECT and SOCKS5
-----------------------

An HTTP CONNECT or SOCKS5 tunnel negotiates a path; it does **not**
authenticate the final HTTPS origin. After a *cleartext* CONNECT is
established, Runwire can perform a distinct, verified TLS upgrade to
the intended origin. Validate SNI/hostname/trust/ALPN at this stage.

An HTTPS proxy already wraps the underlying PHP stream in TLS.
Runwire's current PHP stream layering does not provide certified
TLS-inside-TLS for that proxy plus a second HTTPS origin. This
combination intentionally fails closed; do not advertise it as a
working CONNECT configuration.

A proxy must be treated as a distinct trust boundary: proxy credentials
must not reach the target origin, redirects must not forward
origin secrets to a new host and DNS/IP allowlists must be rechecked
against the effective destination.

Pooling and leasing
-------------------

`BoundedConnectionPool` controls concurrent active leases and waiting
requests. It does not itself establish verified connections. An
admitted slot is permission to dial, **not** a socket. `IdleConnectionPool`
stores explicitly quiescent, validated connections, with bounded TTL
and idle capacity. `PoolIsolationKey` binds origin/protocol/TLS identity,
tenant and proxy route; a key hash is not authorization.

A connection is ineligible for pooling if any response bytes remain
unread, body framing is incomplete, GOAWAY forbids new H2 streams,
TLS policy has changed, the response requires close or ownership
of its watchers is ambiguous.

Cancellation and deadline checklist
-----------------------------------

* Use monotonic operation deadlines rather than wall-clock timeouts.
* Cancel pending DNS resolutions, address races, TLS handshakes and
  stream admissions when their request scope expires.
* Always propagate the terminal error exactly once.
* Hand a successful socket to one owner; close it on failed adoption.
* Never retry partially accepted non-idempotent work automatically.
* Account for queued bytes and slow peer behavior in capacity metrics.

Verifiable test commands
------------------------

.. code-block:: console

   vendor/bin/pest tests/DialerTest.php tests/HappyEyeballsDialerTest.php
   vendor/bin/pest tests/TlsClientDialerTest.php tests/Http2OutboundClientTest.php
   vendor/bin/pest tests/StreamRelayTest.php

For independent H2 and H3 interop, use the corresponding workflows
rather than assuming a local unit test covers external peers. See
:doc:`connection-policies-3.0`, :doc:`protocols-3.0` and
:doc:`security-handbook-3.0`.


### Opt-in DNS coalescing and bounded cache (Runwire 3.0 candidate)

`Network\\CachedDnsResolver` composes `AsyncDnsResolver` through the small
`DnsResolverInterface` and may be injected into `HappyEyeballsDialer`.
Existing applications still receive the unmodified, non-caching resolver
when they construct the normal `AsyncDnsResolver` and `HappyEyeballsDialer`.

The cache is **per worker and per constructed tenant scope**. It uses a
bounded positive TTL (default 10 seconds, maximum 300 seconds), optional
negative TTL for explicit DNS no-address answers (default 1, maximum 30),
128 cached hosts, 64 concurrently pending hostnames and 256 per-caller
waiters by default. No shared global cache or stale-while-revalidate.
Coalesced callers have separate cancellation subscriptions and deadlines;
only the last departing waiter cancels the upstream resolution. Cache
hits are delivered deferred on the reactor. Dispose the cache on worker
drain and create a fresh cache after fork. The cache does **not** remove
`DialOptions::addressPolicy` preconnect and kernel-peer checks; TTL cached
DNS never authorizes bypassing SSRF controls, and DNS TTLs are operator
policy rather than authoritative upstream record TTLs.

The cache is independent of host-owned transport support and requires
the same off-loop `ProcessExecutor` as the default resolver. New test:
`tests/CachedDnsResolverTest.php`.


Explicit cross-protocol outbound dispatch
-----------------------------------------

`Http\\Client\\HttpOutboundDispatcher` provides one callback-based
`send()` entry point for the existing verified H1/H2 TLS-ALPN client
and optional native QUIC H3 implementation. Every request receives an
`OutboundHttpHandle` that owns a linked cancellation source through
final settlement, and an H1 reusable socket is aborted on completion
unless retained by an independently qualified owning pool. No ambient
cookies, implicit H3 downgrade, plaintext fallback, cross-origin reuse,
unverified certificate policy or automatic session pool is introduced.
H3 requires explicit `QuicClientOptions` and a strict H3-only policy,
while H1/H2 use caller-owned `HappyEyeballsDialer`. Only the current
per-request transport lifecycle is unified; multi-request H2/H3
pooling and same-origin capacity management remain separately exposed
protocol APIs, pending independent lifecycle, performance and
cross-tenant acceptance.


Explicit bounded HTTP/1.1 keep-alive session
--------------------------------------------

An opt-in `Http\\Client\\PooledHttp1Session` combines the existing verified
`HappyEyeballsDialer`, `BoundedConnectionPool` and `Http1ConnectedExchange`
for a fixed HTTP origin and worker/tenant scope. The constructor requires an
explicit revisioned TLS trust identity when using HTTPS. TLS ALPN is fixed to
`http/1.1`; no H2/H3 reuse or implicit downgrade is advertised. Each request
uses an H1-only protocol policy, owns cancellation and deadlines independently,
and can return a completely drained, keep-alive-eligible socket to the bounded
idle store. The pool rejects stale, buffered or foreign-identity connections,
stops admissions on close, and exposes bounded reuse/retirement counters.
Caller must reconstruct a new session after TLS trust policy, proxy route or
tenant changes. A live two-request same-socket regression with exact accepted
connection count is in `tests/PooledHttp1SessionTest.php`.

This is **H1 connection pooling only**, not a general H1/H2/H3 automatic
pooled session manager. Keep the `HttpOutboundDispatcher` policy separate;
H2 multiplex and native H3 session siblings retain their own explicit lifecycle.


Unified explicit transport and reusable session routing
------------------------------------------------------

The `Http\\Client\\HttpOutboundDispatcher` provides one request ownership,
cancellation and completion contract. With constructor opt-ins, it can
route strict H1 requests into a fixed `PooledHttp1Session` (bounded real
keep-alive sockets), strict H2 requests into a caller-established,
verified and SETTINGS-ready `Http2MultiplexConnection` (bounded independent
stream owners), and strict H3 requests into a caller-established
`Http3OutboundClient` session's independently cancellable siblings.
Without those injected sessions it continues using the existing verified
H1/H2 ALPN dialer or explicitly configured native H3 QUIC transport.

The supplied sessions must be created by the same worker/tenant trust
scope, must be explicitly closed by their owners, and must enforce their
original origin and TLS/ALPN policy. H2/H3 session reuse requires the
caller to establish the protocol session first; this facade does **not**
automatically cache QUIC or multiplex sockets across distinct origins,
tenants or trust-policy revisions. Per-request TCP dial/TLS options on an
injected pooled H1/H2 session are rejected rather than ignored. No H3
fallback to H2 or H1 is attempted. Completion always disposes the request
cancellation source and never silently retains an unverified socket.
