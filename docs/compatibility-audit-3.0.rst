Compatibility audit: Runwire 2.1.2 to 3.0
=========================================

Source and claim boundaries
---------------------------

The published stable comparator is 2.1.2 at immutable commit
`7e9bf20ad0737a187dfed77b723791b141b36378`.
Source-level comparisons do not establish identical runtime behavior,
exception ordering or application compatibility.

This guide complements :doc:`migration-3.0` and
:doc:`migration-playbook-3.0` with a practical final audit sheet.
The source audit uses PHP declaration manifests and runtime
reflection because methods provided through traits can appear
removed to a naive source scanner.

Source-backed compatibility ledger
----------------------------------

The repository retains machine-reviewed 2.1.2-to-3.0 migration evidence
in `qa/release-baseline/`. An earlier source comparison recorded:

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
be recomputed for the 3.0 implementation.

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
This example uses the public runtime and server
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

Real 2.1.2 versus 3.0 HTTP behavioral check on GitHub workers
-------------------------------------------------------------

An independent regression compares Runwire 2.1.2 and 3.0 from separate
checked-out sources. It verifies
real HTTP/1.1 GET, valid subpath, bad path and disallowed POST
status, body, Content-Length and Content-Type on PHP 8.4 and 8.5.
The immutable GitHub `workflow run #40508637661
<https://github.com/infocyph/Runwire/actions/runs/40508637661>`_
passed both runtime versions with saved source-pinned evidence.

That is a scoped behavioral regression only. Changed exception
ordering, process shutdown/reload, TLS/QUIC/H2/H3 handling, all
intentional API breaks and maintained callback contracts still need
the test-backed frozen-candidate migration review; U16.12 is already accepted but source-level equivalence is not universal behavioral compatibility.
Complete public implementation migration ledger (2.1.2 to 3.0)
---------------------------------------------------------------

In addition to the old/new HTTP-wire regression, Source Audit exports
the *current* property-aware manifest and compares all public declarations,
public methods, public properties, constants and enum cases against the
published 2.1.2 revision. The canonical machine-reviewed file is
``qa/release-baseline/c4-migration-behavior-ledger.json``.
Each changed implementation must have an explicit behavioral description,
application migration instruction, real executable regression filename,
and release-review flag; ``tools/c4-migration-behavior-audit.php``
requires set equality against the source-generated change list. New
unreviewed changes fail the independent GitHub Source Audit.

An earlier comparison recorded **32 changed public implementation hashes**.
The count refers to that snapshot, not every subsequent revision.
These are *not* automatically breaking declaration changes. The source
and runtime contracts below govern applications upgrading the published
package, especially applications using custom host adapters.

.. list-table:: Migration groups represented by all 32 changed public implementations
   :header-rows: 1
   :widths: 20 44 36

   * - Group
     - Covered changed implementations
     - Executable review and disposition
   * - Structured coroutines
     - CoroutineScope, FutureState, AsyncConnection
     - CoroutineHardeningTest, BatchLCoroutineSchedulerTest,
       AsyncConnectionTest: closed-scope rejection, exactly-once completion
       and worker-owned connection cleanup
   * - HTTP/2
     - Http2Connection, ControlFrameBudget, ResponseScheduler
     - Http2ConnectionTest, Http2ControlFrameBudgetTest,
       Http2AdaptiveResponseStrategyTest: accepted streams drain after
       GOAWAY; scheduling credit callback remains optional
   * - Native HTTP/3
     - PhpQuicHttp3Connection, PhpQuicHttp3Worker, PhpQuicTransport
     - PhpQuicHttp3ConnectionTest, PhpQuicHttp3WorkerTest,
       QuicWorkerSessionTest: extension/capability, bounded streams
       and optional connection failure observer
   * - Event loop
     - SelectLoop
     - SelectLoopTest: bounded watcher/timer lifetime and select fallback
   * - Connections/transport
     - Connection, DatagramListener, CloseReason, ConnectionState,
       ByteQueue, ConnectionCallbackOwnership, TcpSocketTuner,
       TlsHandshake, TcpListener, TlsOptions, UnixListener
     - ConnectionTest, ConnectionStateOwnershipTest, ByteQueueReadTest,
       ConnectionScopeOwnershipTest, ConnectionSocketPolicyTest,
       TlsHandshakeReadinessTest, TcpListenerForkOwnershipTest,
       TlsListenerTest, UnixListenerTest, DatagramListenerTest:
       strict EOF, origin verification, resource ownership, budgets
   * - Hosted runtime
     - HostRequestFactory
     - HostRuntimeTest and real hosted worker workflows:
       host request ownership, no duplicate native supervisor
   * - Supervisor/process
     - ChildReaper, ChildSet, PeriodicTaskRegistry,
       ReadinessChannel, Supervisor, WorkerContext, WorkerGroup
     - SupervisorLifecycleTest, SupervisorRecycleTest,
       SupervisorLiveAutoscaleTest, NativeWorkerLifecycleTest,
       WorkerContextTest, WorkerRecyclePolicyTest: readiness,
       generation isolation, scale transitions and orderly drain
   * - WebSocket
     - WebSocketFrameParser, WebSocketWireCodec, WebSocketSession
     - WebSocketFrameParserTest, WebSocketBidirectionalWireTest,
       WebSocketClientRoleTest: role-specific mask and close behavior

Before/after: existing public connection methods
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

**Before, published 2.1.2:** Calling the existing connection's
``write()`` and ``onClose()`` methods remains supported:

.. code-block:: php

   <?php
   $connection->onClose($onClosed);
   $result = $connection->write($payload);

**After, Runwire 3.0:** The identical application call shape is
retained, although the two method implementations now come from
internal composing traits. There is **no** need to rewrite callers
merely because a declaration-only file scanner reports these as
removed. Preserve exact byte limits and callback ownership.

Before/after: an explicitly asynchronous file read
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

**Before, published 2.1.2:** Ordinary PHP file reads are blocking.
Applications must not assume they become nonblocking inside a
coroutine:

.. code-block:: php

   <?php
   $contents = file_get_contents($path);

**After, unreleased 3.0 (opt-in):** An application that needs
nonblocking reactor progress can own a bounded worker executor
and file facade, and close the executor after all callbacks drain:

.. code-block:: php

   <?php
   use Infocyph\Runwire\Executor\ProcessExecutor;
   use Infocyph\Runwire\Filesystem\AsyncFileSystem;
   use Infocyph\Runwire\Loop\SelectLoop;

   $loop = new SelectLoop();
   $executor = new ProcessExecutor($loop, 1, 8);
   $files = new AsyncFileSystem($executor);

   $files->read($path, 4096, static function (
       string $bytes,
       ?Throwable $error
   ) use ($loop): void {
       if ($error !== null) {
           // Route the failure to your application error policy.
       } else {
           // Consume a bounded immutable chunk.
       }
       $loop->stop();
   });

   $loop->run();
   $executor->close();

This uses bounded **PHP subprocesses**, not qualified kernel AIO,
and new public file/loop classes do not exist in the published
2.1.2 package. Do not replace synchronous file operations
unconditionally without evaluating cancellation and side effects.

Before/after: transport and native host capability decisions
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

**Before:** A 2.1.2 application continues to own its established
server entrypoint:

.. code-block:: php

   <?php
   use Infocyph\Runwire\Runtime;
   use Infocyph\Runwire\Server;

   Runtime::create()->listen(Server::http('127.0.0.1:8080', $handler))->run();

**After:** The server entrypoint remains the same. For 3.0-only
native outbound HTTP/3 you must select the separate QUIC-specific
client, enable an actually supported native extension, and verify
the peer; the HTTP/1.1/2 negotiating client cannot silently
downgrade an H3-only request. FPM, FrankenPHP and RoadRunner are
host-owned and must **not** start a second prefork supervisor.
There is no 3.0 WebTransport implementation. A verified TLS
connection through a cleartext CONNECT proxy remains supported;
nested TLS through an HTTPS proxy deliberately fails closed.
Keep 2.1.2 artifacts/configuration for rollback.

The reviewed ledger and source audit **do not** assert blanket
binary, API-behavior or exception equivalence. Unknown API changes,
a missing test or an additional modified public symbol invalidate
the ledger until explicitly dispositioned. Recompute the diff after source changes.

How to reproduce the compatibility review
-----------------------------------------

For each removed declaration, changed signature, behavior or
error contract, the maintainer must record a **before** sample from
published 2.1.2, an **after** sample from Runwire 3.0,
intentional compatibility choice, real runtime test, rollback
procedure and reviewer disposition.

Source compatibility is only one layer: exception ordering,
worker shutdown, TLS trust, process ownership, protocol
interoperability and performance are separate behavior gates.
This audit does not replace application-specific integration testing.
