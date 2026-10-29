Runwire 2.1.2 to 3.0 migration playbook
=======================================

Migration status and source of truth
------------------------------------

This playbook covers application migration from Runwire 2.1.2 to 3.0.
Keep a known-good previous version, dependencies and configuration ready
for rollback.

A source-level compatibility review compared published 2.1.2 at commit
`7e9bf20ad0737a187dfed77b723791b141b36378` with a Runwire
3.0 development snapshot. It observed 350 versus 414 public named
types, 64 additive types, six optional constructor-argument
extensions, and two apparent removals that were actually public
methods moved to composed traits. This is **not** evidence that all
runtime behavior or exception ordering is unchanged.

Preserved public methods
------------------------

`Network\Connection::onClose(callable): self` remains publicly
available through the internal connection close-observer trait.
`Network\Connection::write(string): WriteResult` remains available
through the internal lifecycle-operations trait. A raw PHP source
scanner can report these as removals unless it resolves traits;
runtime reflection and the source migration regression prove
their continued public availability.

Six reviewed optional constructor extensions
--------------------------------------------

.. list-table:: Existing type and newly optional configuration
   :header-rows: 1
   :widths: 47 53

   * - Type
     - Migration consideration
   * - `Http\Http2\Internal\ResponseScheduler`
     - Optional outbound DATA-credit callback; old argument order preserved
   * - `Http\Http3\Quic\PhpQuicHttp3Worker`
     - Optional connection-failure observer
   * - `Network\Connection`
     - Optional verified TLS peer fingerprint and strict EOF configuration
   * - `Network\TlsOptions`
     - Optional client-certificate requirement
   * - `WebSocket\Internal\WebSocketFrameParser`
     - Optional client masking expectation
   * - `WebSocket\WebSocketSession`
     - Optional outbound client mode

These six are source-level observations, **not** intentionally breaking
required-parameter changes. Appending an optional parameter preserves
most existing calls, but applications using subclassing, reflection,
named argument assumptions or extra transport policy must be tested.

Server entry point: before and after
------------------------------------

The high-level server construction remains:

.. code-block:: php

   <?php
   use Infocyph\Runwire\Runtime;
   use Infocyph\Runwire\Server;

   // Published 2.1.2 entry pattern; continue using it on 3.0 source.
   $server = Server::http('127.0.0.1:8080', $handler);
   Runtime::create()->listen($server)->run();

The example deliberately uses an injected handler. Before migrating,
test the real application response contract, lifecycle callback order,
TLS options, worker count, EOF semantics and all configured host
drivers on the candidate SHA.

Outbound connection: new optional composition
---------------------------------------------

Published 2.1.2 applications commonly used their own outbound client.
Runwire 3.0 adds a separate loop-bound dial and
origin-verifying TLS stack. **Do not** replace an existing mature
HTTP client automatically merely because the new types exist.

.. code-block:: php

   <?php
   use Infocyph\Runwire\Loop\SelectLoop;
   use Infocyph\Runwire\Network\Dialer;

   // Optional 3.0-only outbound TCP path; no DNS, redirect or TLS here.
   $loop = new SelectLoop();
   $dialer = new Dialer($loop);
   $dialer->connect(
       'tcp://127.0.0.1:8080',
       static function ($connection, ?Throwable $error) use ($loop): void {
           if ($connection !== null) {
               $connection->closeGracefully();
           }
           $loop->stop();
       },
   );
   $loop->run();
   $dialer->close();

For hostnames, compose `AsyncDnsResolver` and
`HappyEyeballsDialer`. For HTTPS, enforce the *original hostname*
through `TlsClientOptions` and validate negotiated ALPN; a
verified TLS client is not the same as server `TlsOptions`.
For HTTP/2 and HTTP/3, use only the specific supported client APIs
and native capabilities, never a fabricated fallback.

Capability changes and exclusions
---------------------------------

.. list-table:: Migration decision
   :header-rows: 1
   :widths: 34 66

   * - Feature
     - Required handling
   * - HTTP/1.1 and H2 serving
     - Compare framing, response correctness, keepalive, GOAWAY and timeout
   * - HTTP/3 over native QUIC
     - Probe extension availability and real external H3 peer on target host
   * - Outbound TLS and HTTP
     - Opt in, verify origin, ALPN, redirect secret stripping and timeouts
   * - WebSocket
     - H1 upgrades remain; outbound client is opt in
   * - WebTransport
     - Excluded; no compatibility claim or fake QUIC DATAGRAM support
   * - Proxy CONNECT
     - Plain CONNECT then verified HTTPS origin supported in scope
   * - HTTPS proxy to HTTPS origin
     - Nested TLS-over-TLS explicitly unsupported and fails closed
   * - Async files
     - Bounded separate PHP worker processes, not native disk AIO
   * - Native autoscale
     - Real prefork supervisor only; do not enable in FPM host request
   * - Coroutines
     - Explicitly own tasks, deadlines, background handoff and cleanup

Behavioral compatibility audit
------------------------------

The current exhaustive symbol-level behavior mapping, intentional
capability exclusions and runnable before/after migration examples
are documented in :doc:`compatibility-audit-3.0`.
The source manifest guard checks public declarations, promoted
properties, constants, enum cases and reviewed constructor
signatures, but it does not prove behavior. Check these manually and
in automated integration tests:

* exception type and error callback ordering;
* close callback after local/remote EOF, TLS close-notify policy;
* duplicate completion and cancellation races;
* default request/connection buffer and deadline behavior;
* persistent host request reset and coroutine task isolation;
* TLS hostname/CA/ALPN and proxy SNI after DNS or redirect;
* QUIC/H3 available versus unavailable semantics;
* file-operation side effects after cancellation;
* native process readiness, recycle, reload and signal handling.

The exact manifest and comparison tools are
`tools/public-api-manifest.php` and
`tools/public-api-migration-audit.php`. Run the published
baseline and candidate through the **same** version of those tools
and archive raw diffs. A property-aware source audit identified
32 changed public implementations and the test-backed exact-diff
disposition ledger under
``qa/release-baseline/c4-migration-behavior-ledger.json``.
The source audit now fails if a changed public implementation lacks a
migration disposition and executable regression test. Recompute the comparison after changing the checked-out source.

Migration phases and rollback
-----------------------------

**Phase 1 — Baseline.** Freeze existing 2.1.2 behavior, live config,
extension inventory, traffic shape, latency, errors, CPU/RSS and FD.
Collect known-good integration tests and a rollback artifact.

**Phase 2 — API scan.** Compare public symbol manifests and source
code references, including trait-composed methods. Review every
changed construction, inherited method and callback signature.

**Phase 3 — Capability audit.** Select a supported runtime driver on
the real host, check QUIC/sockets/PCNTL/process-spawn capability,
test origin TLS and all required protocol versions. Exclude
WebTransport paths entirely.

**Phase 4 — Functional staging.** Run independent H1/H2/H3 clients,
host suites, native crash/reload, mixed outgoing connections and
file-worker cancellation under real quotas. Confirm request reset
between persistent host requests.

**Phase 5 — Performance qualification.** Execute matched
five-pair 180-second baseline/candidate study and 1800-second
process-tree soak. Do not promote shorter diagnostics to that
criterion.

**Phase 6 — Canary.** Roll out to a small controlled share; monitor
admitted/completed/error counts, tail latency, queue pressure,
worker restarts and verified TLS failures.

**Phase 7 — Freeze and approval.** Re-run all exact-head QA,
update migration/reference documentation, obtain maintainer signoff
and obtain approval before deploying the application update.

Rollback is to the separately supported previous artifact and
configuration, not an unreviewed Git branch. Ensure application
schema changes can be rolled back independently.

Before/after acceptance records
-------------------------------

Record each migrated integration as a structured table containing
old API behavior, new expected behavior, source signature, actual
real-peer test and reviewer decision. Treat any new necessary
breaking migration path as an explicit release note with a runnable
before/after code sample, not as a silently changed default.

For supporting details, see :doc:`migration-3.0`,
:doc:`api-reference-3.0` and
:doc:`production-operations-3.0`.
