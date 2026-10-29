Hands-on Runwire 3.0 learning path
==================================

Read this before starting
-------------------------

These Runwire 3.0 exercises use runnable source examples, independent
network peers and observable cleanup rather than assumed cloud services.

Run with 64-bit PHP 8.4 or 8.5, Composer and free loopback ports.
Prefork stages also require PCNTL/POSIX; TLS stages require OpenSSL,
and HTTP/3 requires an **actual compatible native QUIC implementation**.
Shared hosting can prohibit fork, daemons and native QUIC.

.. code-block:: console

   php -v
   php -m
   composer validate --strict
   composer install --no-interaction --prefer-dist --no-progress
   php tests/fixtures/portable_native_acceptance.php

Do not interpret portable success as native prefork or QUIC qualification.

Exercise A: run a minimal loopback HTTP listener
-------------------------------------------------

The public entry point is `Infocyph\Runwire\Runtime`; `Server::http`
binds a listener with an application-defined response handler.
This minimal server occupies the current CLI process until stopped.
Run the listener in one terminal and query it from another.

.. code-block:: php

   <?php
   declare(strict_types=1);

   require __DIR__ . '/vendor/autoload.php';

   use Infocyph\Runwire\Http\HttpRequest;
   use Infocyph\Runwire\Http\ResponseWriterInterface;
   use Infocyph\Runwire\Runtime;
   use Infocyph\Runwire\Server;

   $server = Server::http(
       '127.0.0.1:8080',
       static function (HttpRequest $request, ResponseWriterInterface $writer): void {
           $writer->end("Hello from Runwire\n");
       },
   );

   Runtime::create()->listen($server)->run();

.. code-block:: console

   curl --include http://127.0.0.1:8080/

In production, choose a deliberate bind address, TLS policy,
admission ceilings and termination model. A port that binds is not
necessarily a worker that has reported application readiness.

Exercise B: classify runtime ownership correctly
--------------------------------------------------

The native master owns worker generations; each child owns its loop
and request sessions. In portable mode a single event loop handles
progress without claiming prefork supervision. Host-owned FPM,
FrankenPHP, RoadRunner and Swoole runtimes have separate lifetime rules.

Before adding asynchronous code, answer these questions:

#. Who creates and closes the listener?
#. Who owns a successful dialed connection?
#. Who may cancel a pending DNS lookup or stream request?
#. If a fork occurs, is the watcher owned by the parent or child?
#. Does stop mean graceful drain or immediate abandonment?
#. What happens when a cleanup callback itself throws?

Real prefork ownership regression:

.. code-block:: console

   php tests/smoke/native_listener_fork_smoke.php
   php tests/smoke/native_dialer_smoke.php
   php tests/smoke/native_cleanup_fault_smoke.php

These commands require a permitted CLI process with PCNTL. See
:doc:`runtime-lifecycle-3.0` and :doc:`supervision-handbook-3.0`.

Exercise C: keep TCP, TLS and HTTP identities separate
------------------------------------------------------

A TCP connection to a numeric IP is not proof that the intended
hostname is trusted. DNS result selection, TCP readiness, TLS chain
and hostname validation, ALPN selection and HTTP request framing
are separate phases.

.. code-block:: console

   vendor/bin/pest tests/TlsClientDialerTest.php tests/Http2OutboundClientTest.php
   vendor/bin/pest tests/WebSocketOutboundClientTest.php

For a hostname keep the original requested authority through DNS and
Happy Eyeballs. An origin certificate failure must not silently turn
into plaintext. Only reuse pooled sockets when origin, tenant, TLS
identity, proxy policy and protocol settings match.
See :doc:`outbound-handbook-3.0` and :doc:`security-handbook-3.0`.

Exercise D: learn admission, cancellation and backpressure
-----------------------------------------------------------

For every network and file operation record: maximum pending count,
timer/deadline source, buffered-byte ceiling, cancellation state,
completion callback cardinality and terminal cleanup owner.
A queue with accepted but never delivered output is not successful.

.. code-block:: console

   vendor/bin/pest tests/Http2OutboundClientTest.php
   vendor/bin/pest tests/AsyncFileApiContractTest.php
   vendor/bin/pest tests/AsyncFilesystemSocketFairnessTest.php

Trace the difference between client attempt, server-accepted stream,
headers sent, full body received and final process cleanup.
A soft drain may complete admitted requests, while SIGKILL does not.
Read :doc:`protocol-failure-atlas-3.0` before adding retries.

Exercise E: bounded filesystem processing
------------------------------------------

Ordinary PHP filesystem functions may block the reactor. The 3.0
filesystem facade uses a **bounded off-reactor subprocess executor**,
not qualified kernel-native asynchronous file I/O.

.. code-block:: console

   vendor/bin/pest tests/AsyncFileSystemTest.php
   vendor/bin/pest tests/AsyncFileSystemSecurityTest.php
   php tests/smoke/native_filesystem_smoke.php

Pathname checks are not a race-free sandbox. If another actor can
mutate symlink ancestors between validation and the actual open,
use OS isolation or descriptor-relative facilities outside this
portable facade.

Exercise F: real worker generation and autoscale
-------------------------------------------------

Run these only where signals and fork are supported:

.. code-block:: console

   php tests/smoke/native_supervisor_smoke.php
   php tests/smoke/native_supervisor_fault_smoke.php
   php tests/smoke/native_concurrent_fault_smoke.php
   php tests/smoke/native_autoscale_http_traffic.php

Observe readiness, actual worker-count transitions, restart budget,
reload generation handoff, controlled scale-in, exact HTTP replies
and final child PID cleanup. A 120-second native supervisor soak is
a useful regression, but **not** the final U15 1800-second process-tree
certification.

Exercise G: prove protocols with independent counterparts
-----------------------------------------------------------

HTTP/1.1, HTTP/2 and HTTP/3 have different framing and flow-control
semantics. The independent H2 peer uses hyper-h2. The native H3
interoperability workflow uses real QUIC plus independent aioquic.
An emulated UDP listener cannot stand in for native QUIC.

The repository's `benchmarks/mixed_native_lifecycle.py` additionally
tests real H1/H2/H3/TCP/UDP peers across restart, recycle and graceful
shutdown, with explicit admission and accepted-response accounting.
Run it only with its native QUIC test environment and supporting
Python requirements.

Exercise H: finish with an honest evidence sheet
-------------------------------------------------

A reproducible lab records the exact SHA, PHP/runtime extensions,
host type, test command, observed peer correctness, resource state,
warnings, exceptions and unverified capabilities. Do not promote a
skipped native-QUIC lane to success on an incapable platform.

Use :doc:`operations-exercises-3.0` for rollback scenarios,
:doc:`compatibility-audit-3.0` for published-version migration and
:doc:`performance-lab-3.0` for performance test methods and representative load scenarios.
