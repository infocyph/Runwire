Compatibility audit: from published 2.1.2 to unreleased 3.0
==========================================================

Source and claim boundaries
---------------------------

The published stable comparator is 2.1.2 at immutable commit
`7e9bf20ad0737a187dfed77b723791b141b36378`.
The 3.0 candidate is still changing on PR #9. A declaration
comparison for an older candidate is not a final 3.0 ABI,
behavior or exception-ordering guarantee.

This guide complements :doc:`migration-3.0` and
:doc:`migration-playbook-3.0` with a practical final audit sheet.
The source audit uses PHP declaration manifests and runtime
reflection because methods provided through traits can appear
removed to a naive source scanner.

Source-backed compatibility ledger
----------------------------------

The repository records earlier reviewed 2.1.2-to-3.0 deltas under
`docs/plans/evidence/runwire-3.0/`. That earlier comparison had:

.. list-table:: Previously reviewed source-level observations
   :header-rows: 1
   :widths: 29 29 42

   * - Aspect
     - Observed snapshot
     - Interpretation
   * - Named PHP types
     - 350 baseline, 414 candidate
     - 64 additive types; not automatically enabled on all hosts
   * - Apparent removed methods
     - Two
     - Both still publicly exposed through composed traits
   * - Signature deltas
     - Six constructors
     - Appended **optional** parameters, old argument order retained
   * - Property inventory
     - Compared on both sides
     - Constructor promotions also require compatibility checks
   * - Changed implementation hashes
     - 26 earlier observations
     - Runtime behavior requires separate review

Those quantities belong to the **reviewed prior snapshot** and should
be recomputed for the actual final 3.0 release candidate.

Public methods preserved by traits
-----------------------------------

`Network\Connection::onClose(callable): self` is now implemented
through `Network\Internal\ConnectionCloseObserverTrait`.

`Network\Connection::write(string): WriteResult` is now implemented
through `Network\Internal\ConnectionLifecycleOperationsTrait`.

Both remain public through the composing class. Review the
runtime reflection fixture `tests/PublicApiMigrationCompatibilityTest.php`
instead of treating the missing local method body as a removal.

Optional constructor changes
-----------------------------

.. list-table:: Reviewed constructor additions
   :header-rows: 1
   :widths: 47 53

   * - Existing type
     - Newly optional source parameter meaning
   * - `Http\Http2\Internal\ResponseScheduler`
     - Outbound DATA-credit callback
   * - `Http\Http3\Quic\PhpQuicHttp3Worker`
     - Connection-failure observer
   * - `Network\Connection`
     - Verified TLS peer fingerprint and strict EOF
   * - `Network\TlsOptions`
     - Client certificate requirement
   * - `WebSocket\Internal\WebSocketFrameParser`
     - Client-mask expectation
   * - `WebSocket\WebSocketSession`
     - Outbound client mode

Appending an optional argument alone is not a breaking required
argument change. Still audit default values, thrown errors,
callback timing, cancellation and host-owned lifecycle differences.

Before and after: basic inbound server
---------------------------------------

The stable native server construction pattern remains recognizable.
This development-branch example uses the public runtime and server
entry points:

.. code-block:: php

   <?php
   declare(strict_types=1);

   use Infocyph\Runwire\Http\HttpRequest;
   use Infocyph\Runwire\Http\ResponseWriterInterface;
   use Infocyph\Runwire\Runtime;
   use Infocyph\Runwire\Server;

   require __DIR__ . '/vendor/autoload.php';

   $server = Server::http(
       '127.0.0.1:8080',
       static function (HttpRequest $request, ResponseWriterInterface $writer): void {
           $writer->end('ok');
       },
   );

   Runtime::create()->listen($server)->run();

Even when source constructors remain compatible, verify security
defaults, TLS identity, limits, EOF behavior and host process model.
Do not paste 3.0-only networking/file types into an application
still installing the stable Composer package.

Behavioral migration review matrix
-----------------------------------

.. list-table:: Required 2.1.2-to-3.0 behavioral checks
   :header-rows: 1
   :widths: 30 35 35

   * - Surface
     - Potential migration difference
     - Required evidence
   * - Runtime driver
     - Native vs portable/host process owner
     - Real driver and host tests
   * - Request scope
     - Cancellation and cleanup ordering
     - Inbound request and coroutine tests
   * - Stop and reload
     - New generation handoff and accepted stream drain
     - Signal, reload, independent H2/H3 peer
   * - TLS
     - Verification, ALPN, strict EOF and client auth
     - Real untrusted/valid certificate peers
   * - Outbound networking
     - DNS, staggered dial and connection reuse identity
     - Off-loop resolver and pool isolation tests
   * - File I/O
     - Bounded process-worker execution
     - Async file correctness and socket fairness tests
   * - WebSocket
     - Client masking and WSS handshake policy
     - Real H1 upgrade and outbound client tests
   * - Protocol budgets
     - H1/H2/H3 header, body and stream backpressure
     - Adversarial protocol acceptance suite
   * - Proxy
     - Cleartext CONNECT TLS and unsupported nested TLS
     - Exact origin identity and fail-closed negative tests

Honest feature exclusions
-------------------------

WebTransport is removed from 3.0. Native QUIC capability alone
does not create RFC 9221 QUIC DATAGRAM/WebTransport support.

The async filesystem uses bounded off-reactor subprocesses, not
qualified kernel-native AIO or a race-free PHP pathname sandbox.

HTTPS-proxy-to-HTTPS-origin nested TLS is unsupported; rejecting it
is not a protocol defect to be hidden behind plaintext fallback.

H1 WebSocket support does not certify WebSocket over H2/H3
extended CONNECT.

Reproducible source audit
-------------------------

The implementation includes `tools/public-api-manifest.php`,
`tools/public-api-diff.php` and
`tools/public-api-migration-audit.php`, with an immutable
`public-api-2.1.2.lock.json` baseline under the internal
evidence tree. The existing Source Audit workflow uses reviewed
arguments and compares both property-aware manifests; the exact
commands and artifact format are defined by that workflow.
A custom unreviewed manifest schema is not equivalent evidence.

.. code-block:: console

   vendor/bin/pest tests/PublicApiMigrationCompatibilityTest.php

Before accepting U16.12
-----------------------

For each removed declaration, changed signature, behavior or
error contract, the maintainer must record a **before** sample from
published 2.1.2, an **after** sample from the frozen 3.0 candidate,
intentional compatibility choice, real runtime test, rollback
procedure and reviewer disposition.

Source compatibility is only one layer: exception ordering,
worker shutdown, TLS trust, process ownership, protocol
interoperability and performance are separate behavior gates.
This page prepares the audit; it does **not** by itself close
U16.12 or certify final 3.0 availability.
