Runwire 2.1.2 to 3.0 development migration guide
==================================================

This guide is an implementation-aligned **draft** for unreleased Runwire 3.0.
The published stable release is still 2.1.2. No source incompatibility
or new public API should be treated as shipped until the final freeze and
public-manifest comparison in U16.12/U17.01 have passed.

Core migration boundary
-----------------------

The high-level ``Runtime::create()->listen()->run()`` server model remains
the recommended application entry point. Existing server connection and
response-writer ownership is preserved while 3.0 adds a separate outbound
dial path. Avoid adapting accepted server streams to outgoing connections
without explicitly handing their callback owner and cancellation scope off.

.. code-block:: php

   use Infocyph\Runwire\Loop\SelectLoop;
   use Infocyph\Runwire\Network\Dialer;

   // In development: this outbound dialer complements 2.1.2 servers.
   $loop = new SelectLoop();
   $dialer = new Dialer($loop);
   $dialer->connect('tcp://127.0.0.1:8080',
       static function ($connection, ?Throwable $error) use ($loop): void {
           $connection?->abort();
           $loop->stop();
       });
   $loop->run();
   $dialer->close();

TLS and client protocol policy
------------------------------

Outgoing TLS is deliberately separate from server ``TlsOptions``. 3.0
introduces ``TlsClientOptions``, with certificate/hostname verification,
mandatory original peer identity and explicit ALPN. Do not pass a server TLS
context into an outbound client, suppress verification, retry HTTPS as
plaintext, or forward authentication headers to redirected origins.

A direct ``Http1OutboundClient`` requires the request policy to permit
HTTP/1.1 before DNS/dial admission; an H2-only/H3-only preferred policy
must fail closed. The ``Http1PolicyClient`` redirect helper is optional,
bodyless GET/HEAD only, and does not supply a cookie jar or transparent
HTTP/2/3 fallback.

Opt-in verified H1/H2 client migration (3.0 development)
--------------------------------------------------------

Runwire 2.1.2 does not ship a built-in outbound HTTP client. Existing
consumers relied on their own HTTP clients, not a Runwire client TLS
context. Do not repurpose insecure server-side TLS defaults for dialing.

The unreleased ``HttpNegotiatingOutboundClient`` selects HTTP/1.1 or
HTTP/2 only **after verified TLS/ALPN negotiation**. Explicitly allowing
fallback limits how a peer can choose a nonpreferred version. The experimental native HTTP/3 outbound client is a separate
one-shot HTTPS/h3 path over verified QUIC. It does not yet offer
multiplexed sibling requests, dynamic QPACK unblocking or release
performance certification. The H1/H2 negotiating client cannot satisfy
an H3-only policy and must reject it rather than silently downgrade.

.. code-block:: php

   use Infocyph\Runwire\Executor\ProcessExecutor;
   use Infocyph\Runwire\Http\Client\ClientProtocolPolicy;
   use Infocyph\Runwire\Http\Client\ClientRequest;
   use Infocyph\Runwire\Http\Client\HttpNegotiatingOutboundClient;
   use Infocyph\Runwire\Http\Enum\ProtocolVersion;
   use Infocyph\Runwire\Http\Headers;
   use Infocyph\Runwire\Loop\SelectLoop;
   use Infocyph\Runwire\Network\AsyncDnsResolver;
   use Infocyph\Runwire\Network\Connection;
   use Infocyph\Runwire\Network\Dialer;
   use Infocyph\Runwire\Network\HappyEyeballsDialer;

   $loop = new SelectLoop();
   $executor = new ProcessExecutor($loop, 1, 8);
   $tcpDialer = new Dialer($loop);
   $dialer = new HappyEyeballsDialer(
       $loop, $tcpDialer, new AsyncDnsResolver($executor)
   );

   $policy = new ClientProtocolPolicy(
       ProtocolVersion::HTTP_2,
       [ProtocolVersion::HTTP_2, ProtocolVersion::HTTP_1_1],
       allowFallback: true,
   );
   HttpNegotiatingOutboundClient::connect(
       $loop,
       $dialer,
       new ClientRequest('GET', 'https://example.com/', protocol: $policy),
       static function (int $status, Headers $headers): void {
           // Process the negotiated response without leaking credentials.
       },
       static function (string $part): void {
           // Bound retained response bytes at the application boundary.
       },
       static function (?Connection $unused, ?Throwable $error) use ($loop): void {
           $unused?->abort();
           $loop->stop();
       },
   );
   $loop->run();
   $dialer->close();
   $tcpDialer->close();
   $executor->close();

The single-request client does not enable transparent connection pooling
or H3. ``Http2MultiplexConnection`` is a separate experimental transport
that requires an **already verified** shared TLS+h2 socket, not a
general-purpose HTTP browser or a proxy.

Async filesystem and resource ownership
---------------------------------------

New ``AsyncFileSystem`` delegates bounded read/write/stat/rename/unlink
to a ``ProcessExecutor``. Application code must construct and own an
executor on an appropriate event loop and explicitly close it. A worker-
checked ``rootDirectory`` is opt-in, not a race-free filesystem sandbox.
Migrations must not treat PHP's normal synchronous file functions as
nonblocking merely because they execute inside a coroutine.

``BoundedConnectionPool`` adds lease permits, FIFO waiter caps and
identity-scoped idle storage, but callers still dial independently and
release every lease on both success and failure. Stream relays preserve
cleartext half-close and cancel their own callbacks; a TCP half-close is
not an authenticated TLS close_notify.

Experimental HTTP CONNECT and SOCKS5 stages
--------------------------------------------

The experimental HTTP CONNECT and SOCKS5 tunnel adapters support
authenticated negotiation on already established Runwire connections,
but remain **not an HTTPS-through-proxy client**. The outbound
TLS dialer must first verify the proxy certificate and hostname;
the configured proxy name must match the authenticated peer before
Basic or RFC 1929 credentials may be transmitted. Constructing a codec
with a verified-transport flag alone does not authorize credentials.
Local/remote DNS policy, bounded framing, cancellation and callback
handoff remain explicit.

The original destination's nested TLS handshake, certificate identity
and ALPN are still unimplemented across authenticated proxy tunnels.
A 200 CONNECT reply or successful SOCKS5 handshake proves neither
the original target identity nor permission to weaken TLS checks.

Keep production HTTPS proxy clients unchanged until independently verified
proxy and original-target TLS reference tests have passed U10.04–U10.06.

Compatibility and release verification
--------------------------------------

Native prefork workers require PCNTL/POSIX; PHP-FPM, FrankenPHP,
RoadRunner and Swoole continue to own their host-specific listener/worker
lifecycle. Optional ext-event, ext-swoole and ext-quic are not mandatory
Composer dependencies. HTTP/2 has independently tested one-shot and
experimental multiplexed TLS clients in 3.0 development. The HTTP/3 request
client has a verified one-shot experimental implementation, but full
H3 QPACK/multiplexing, CONNECT/SOCKS5 original-target TLS, WebTransport
and final release benchmarks are **not complete**; no unreleased APIs
should be treated as shipped.

The final migration acceptance requires an exact reflected 2.1.2 versus
3.0 manifest diff for removed/renamed symbols, changed public parameters,
enum cases, return types and exception behavior. This page intentionally
does not claim that incomplete audit is finished.

.. code-block:: console

   php tools/public-api-manifest.php src /tmp/runwire-current-api.json $(git rev-parse HEAD)
   vendor/bin/pest tests/Plan3TrackerSynchronizationTest.php

The 2.1.2 baseline manifest is pinned independently under the development
tracker; never relabel current source as the historical 2.1.2 revision.

Automated source-signature comparison (development)
---------------------------------------------------

Run the source-only API manifest exporter against an unchanged checkout of
published 2.1.2 and the 3.0 development revision, then compare them with
the deterministic CLI diff tool. Source fingerprints do **not** prove
behavioral compatibility, exceptions at runtime, or release readiness.

.. code-block:: console

   php tools/public-api-diff.php /path/to/2.1.2-api.json /path/to/3.0-api.json

The output reports removed types, removed methods, changed signatures,
constant and enum-case changes, and newly declared types, without loading
optional PHP extensions or invoking network APIs.

The Source Audit workflow additionally regenerates both immutable source
manifests with ``--with-properties``, checks the property inventory,
runs the deterministic diff and archives ``migration-source-diff.json``.
The generated delta is a **source-only input**, not proof of behavioral
compatibility or approval to publish. The final release audit
must still document each intentional breaking change, review callback and
exception behavior and pin both immutable source revisions. **U16.12 and
U17.01 are not yet complete.**

Reviewed 2.1.2-to-3.0 source API differences
----------------------------------------------

The W97 [1]_ independently archived Source Audit exported the pinned 2.1.2
public API (350 PHP types) and this unreleased 3.0 development snapshot
(414 types) with **complete public property inventories**. The source-only
diff reports 64 added types, two apparent removed methods, six constructor
signature changes and 26 implementation hashes changed without declaration
changes.

The two apparent removals are **not runtime API removals**:
``Connection::onClose(callable): self`` is implemented by
``ConnectionCloseObserverTrait``, and
``Connection::write(string): WriteResult`` by
``ConnectionLifecycleOperationsTrait``. Both are composed by
the same public ``Connection`` class, and dedicated reflection tests
verify their signatures and continued availability.

The six changed constructors append optional parameters, retaining the
previous required-argument lists: H2 response scheduler credit callback,
H3 worker failure observer, established Connection TLS fingerprint/strict
EOF, server TlsOptions client-certificate requirement, WebSocket parser
mask expectation, and WebSocketSession client mode. Defaults preserve
the original call shapes; security/behavioral regression requires its own
integration tests.

Reflection-backed constructor compatibility audit
-------------------------------------------------

The six reviewed optional 2.1.2-to-3.0 constructor extensions are checked
against live PHP Reflection, not just source-text fingerprints. The
regression suite pins every public constructor's required parameter count,
total count and final optional argument default. Connection also checks
that opt-in TLS fingerprint and strict EOF parameters remain optional.

.. code-block:: console

   vendor/bin/pest tests/PublicApiMigrationCompatibilityTest.php
   vendor/bin/pest tests/PublicApiMigrationConstructorCompatibilityTest.php

These are backward-call-shape checks, not runtime behavior or complete
exception compatibility certification. The independent protocol/host
suites and U17 remain responsible for those other release gates.

The independently versioned review inventory and exact signature hashes
are in ``docs/plans/evidence/runwire-3.0/u16-reviewed-api-delta.json``
and ``u16-reviewed-public-api-migration.md``. The ordinary
Source Audit fails on unreviewed public removals, changed signatures,
property/enum/constant changes or incomplete source inventories, and
records the checked output as ``migration-reviewed-contract.json``.
Its strict source check does **not** establish behavioral compatibility.
U16.12 is still open until all intentionally changed operations, errors
and runtime ownership migration cases are reviewed at the frozen revision.

.. [1] Exact source and baseline artifacts:
   https://github.com/infocyph/Runwire/actions/runs/40163809510

Public property inventory note
------------------------------

New opt-in schema-v1 manifest exports include ``publicProperties`` declarations and
constructor-promoted fields using the final ``--with-properties`` CLI option.
Default exports remain byte-for-byte compatible with the pinned U00 source
manifest integrity guard. The pinned historical 2.1.2 source manifest was
created before this optional field existed. The diff CLI therefore reports
``propertyInventoryCompared: false`` when comparing against that older archive;
it **does not** assert that removed properties were audited in that case.
Before U16.12 acceptance, regenerate a 2.1.2 source-only manifest with the
same exporter version using ``--with-properties`` and repeat the immutable
baseline comparison without modifying the original pinned source-manifest lock. Property
and method behavior still requires maintainer-reviewed migration examples.
