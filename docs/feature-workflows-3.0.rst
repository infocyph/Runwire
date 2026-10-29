Feature walkthroughs and decision guide
=======================================

This chapter explains what each major feature does, why it exists, when it
should be selected, how to use it and how to verify failures. Examples are
for 64-bit PHP 8.4+; examples that require optional
extensions must be tested on the intended deployment host.

What to choose and when
-----------------------

.. list-table:: Select APIs according to the actual workload
   :header-rows: 1
   :widths: 25 35 40

   * - Requirement
     - Use
     - Reason / limitation
   * - HTTP service
     - ``Server::http`` with ``Runtime``
     - Native CLI listener; FPM and RoadRunner own their workers
   * - TCP or Unix IPC
     - ``StreamServer`` and framing codec
     - TCP reads are byte ranges, not complete application messages
   * - UDP endpoint
     - ``DatagramServer``
     - Messages can be lost, duplicated or reordered
   * - Nonblocking outbound connect
     - ``Dialer`` and ``HappyEyeballsDialer``
     - DNS, connected IP and TLS identity are separate trust checks
   * - Outbound HTTP requests
     - ``HttpOutboundDispatcher``
     - Explicit H1/H2/H3 policy; no automatic QUIC downgrade
   * - Repeated same-origin H1
     - ``PooledHttp1Session``
     - Reuses H1 only; caller owns tenant and trust revision
   * - Concurrent application tasks
     - ``CoroutineScope`` and a bounded task/channel
     - Cancellation does not make synchronous I/O nonblocking
   * - File reads on a busy reactor
     - ``AsyncFileSystem`` and ``ProcessExecutor``
     - Separate CLI workers, not kernel-native AIO
   * - Native worker orchestration
     - ``Supervisor`` and ``WorkerGroup``
     - Needs real POSIX/PCNTL; hosts manage their own workers
   * - Request tracing
     - ``TraceContext`` and ``TraceScope``
     - Explicit W3C carrier and Fiber ownership, no automatic SDK

Walkthrough 1: full loopback HTTP server
----------------------------------------

**What.** An application handler receives an admitted request and sends a
response through the request's writer. **Why.** It separates transport
framing from application output. **When.** Start here for a CLI service
or an isolated integration test; add authentication, limits, TLS and
supervision before exposing a public port.

Save as ``server.php`` in the repository root:

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
       'local-api',
   );

   Runtime::create()->listen($server)->run();

In one terminal start the server and in another inspect the complete
HTTP response:

.. code-block:: console

   php server.php
   curl --fail-with-body --include http://127.0.0.1:8080/

The ``php server.php`` process remains running until stopped. The
curl command belongs in a **second terminal**. Confirm the literal
response body, then stop the server with the appropriate signal. A port
binding alone does not prove a worker is ready or a response completed.

**Failure cases.** A port may already be allocated. An exception before
``end()`` can leave a response unfinished. Do not retry a mutating
request blindly after a connection closes; its handler may have executed.
The full real-port acceptance fixture additionally checks TCP, Unix,
UDP and cleanup.

.. code-block:: console

   php tests/fixtures/portable_native_acceptance.php

See :doc:`inbound-handbook-3.0` and :doc:`runtime-lifecycle-3.0`.

Walkthrough 2: complete outbound HTTP/1.1 request
--------------------------------------------------

**What.** ``HttpOutboundDispatcher`` sends one request with separate
header, body and terminal callbacks. **Why.** Origin policy, DNS,
connect, TLS, ALPN and response parsing have different ownership and
failure phases. **When.** Choose it for callback-driven outbound HTTP;
it does not provide a process-global cookie jar or automatic H2/H3
session discovery.

Keep Walkthrough 1 running on port 8080 and save ``client.php``:

.. code-block:: php

   <?php

   declare(strict_types=1);

   require __DIR__ . '/vendor/autoload.php';

   use Infocyph\Runwire\Executor\ProcessExecutor;
   use Infocyph\Runwire\Http\Client\ClientProtocolPolicy;
   use Infocyph\Runwire\Http\Client\ClientRequest;
   use Infocyph\Runwire\Http\Client\HttpOutboundDispatcher;
   use Infocyph\Runwire\Http\Enum\ProtocolVersion;
   use Infocyph\Runwire\Loop\SelectLoop;
   use Infocyph\Runwire\Network\AsyncDnsResolver;
   use Infocyph\Runwire\Network\Dialer;
   use Infocyph\Runwire\Network\HappyEyeballsDialer;

   $loop = new SelectLoop();
   $executor = new ProcessExecutor($loop, 1, 2);
   $tcp = new Dialer($loop);
   $dialer = new HappyEyeballsDialer($loop, $tcp, new AsyncDnsResolver($executor));
   $http = new HttpOutboundDispatcher($loop, $dialer);
   $status = null;
   $body = '';
   $failure = null;

   $policy = new ClientProtocolPolicy(
       ProtocolVersion::HTTP_1_1,
       [ProtocolVersion::HTTP_1_1],
       allowPlaintext: true,
   );

   try {
       $http->send(
           new ClientRequest('GET', 'http://127.0.0.1:8080/', protocol: $policy),
           static function (int $code) use (&$status): void {
               $status = $code;
           },
           static function (string $chunk) use (&$body): void {
               $body .= $chunk;
           },
           static function (?Throwable $error) use (&$failure, $loop): void {
               $failure = $error;
               $loop->stop();
           },
       );
       $loop->run();
       if ($failure !== null) {
           throw $failure;
       }
       echo $status, ': ', $body;
   } finally {
       $dialer->close();
       $tcp->close();
       $executor->close();
   }

.. code-block:: console

   php client.php
   vendor/bin/pest tests/HttpOutboundDispatcherTest.php

Expect status 200 and the local server response. ``allowPlaintext`` is
only appropriate for this trusted loopback exercise. For user-entered
URLs, enforce an outbound address policy before dialing and recheck the
actual peer. Set deadlines, handle cancellation, and never downgrade
HTTPS silently. For HTTPS configure validated trust/ALPN; for HTTP/3
require explicit native QUIC. An injected H2/H3 session must already
have been established and independently verified.

See :doc:`outbound-handbook-3.0` and
:doc:`connection-policies-3.0`.

Walkthrough 3: trace propagation with no global request identity
-----------------------------------------------------------------

**What.** ``TraceContext`` injects and parses W3C headers. ``TraceScope``
captures explicit request or Fiber context. **When.** Use it to
correlate an outbound sub-operation with an inbound request, not to
authenticate that request or to propagate secrets across tenants.

Save ``trace.php``:

.. code-block:: php

   <?php

   declare(strict_types=1);

   require __DIR__ . '/vendor/autoload.php';

   use Infocyph\Runwire\Http\Headers;
   use Infocyph\Runwire\Tracing\TraceContext;
   use Infocyph\Runwire\Tracing\TraceScope;

   $trace = new TraceContext(str_repeat('a', 32), str_repeat('b', 16));
   $outbound = $trace->inject(Headers::fromArray(['accept' => 'application/json']));
   $received = TraceContext::fromHeaders($outbound);
   if ($received === null) {
       throw new RuntimeException('Missing W3C trace context');
   }

   $scope = new TraceScope();
   $scope->run($received, static function () use ($scope): void {
       $child = $scope->current()?->child();
       if ($child === null) {
           throw new RuntimeException('Lost request trace scope');
       }
       echo $child->inject(new Headers())->first('traceparent'), PHP_EOL;
   });

   if ($scope->current() !== null) {
       throw new LogicException('Context leaked outside the request');
   }

.. code-block:: console

   php trace.php
   vendor/bin/pest tests/TraceContextTest.php tests/TraceScopeTest.php
   vendor/bin/pest tests/ProcessExecutorTraceScopeTest.php

A child shares the trace ID but has a different span ID. Failed or
completed callbacks restore the previous scope. Worker-process IPC and
OpenTelemetry SDK export still require an explicit application bridge.
See :doc:`observability-handbook-3.0`.

Walkthrough 4: bounded off-reactor file reads
---------------------------------------------

**What.** ``AsyncFileSystem`` delegates blocking disk calls to
``ProcessExecutor`` subprocesses. **Why.** A PHP ``file_get_contents``
inside a socket callback can starve the event loop. **When.** Use
bounded file jobs when reactor responsiveness matters and process
creation is supported. It is not kernel-native async disk I/O.

Create ``input.txt`` and save ``read-file.php``:

.. code-block:: php

   <?php

   declare(strict_types=1);

   require __DIR__ . '/vendor/autoload.php';

   use Infocyph\Runwire\Executor\ProcessExecutor;
   use Infocyph\Runwire\Filesystem\AsyncFileSystem;
   use Infocyph\Runwire\Loop\SelectLoop;

   $loop = new SelectLoop();
   $executor = new ProcessExecutor($loop, 1, 4);
   $files = new AsyncFileSystem($executor);

   $files->read(
       __DIR__ . '/input.txt',
       4096,
       static function (string $bytes, ?Throwable $error) use ($executor): void {
           if ($error !== null) {
               fwrite(STDERR, $error->getMessage() . PHP_EOL);
           } else {
               echo $bytes;
           }
           $executor->close();
       },
   );
   $loop->run();

.. code-block:: console

   printf 'file worker example\n' > input.txt
   php read-file.php
   vendor/bin/pest tests/AsyncFileSystemTest.php tests/AsyncFileSystemSecurityTest.php

**Limits.** The existing facade bounds individual I/O payloads to
1 MiB; break large transfers into chunks with downstream backpressure.
Cancellation may not undo filesystem effects already applied by a
worker. The optional canonical-root path check is not race-free against
concurrent symlink changes. Enforce OS privileges and path isolation.
See :doc:`filesystem-handbook-3.0` and :doc:`async-file-io-3.0`.

Walkthrough 5: native worker supervision
----------------------------------------

**What.** ``Supervisor`` owns native child PID/generation, ready
state, restart and drain. **When.** Use this only in a CLI process with
PCNTL/POSIX and a lifecycle your process owns. In FPM, FrankenPHP,
RoadRunner or Swoole, let the host own its worker master.

The next snippet is a **demonstration of worker readiness**, not a
production recommendation for a sleeping job loop:

.. code-block:: php

   <?php

   declare(strict_types=1);

   require __DIR__ . '/vendor/autoload.php';

   use Infocyph\Runwire\Supervisor\Supervisor;
   use Infocyph\Runwire\Supervisor\WorkerContext;
   use Infocyph\Runwire\Supervisor\WorkerGroup;

   $supervisor = new Supervisor();
   $supervisor->group(new WorkerGroup(
       name: 'example-workers',
       count: 2,
       bootstrap: static function (WorkerContext $context): void {
           $context->ready();
           while (!$context->stopping()) {
               usleep(20_000);
           }
       },
       automaticReady: false,
       shutdownTimeoutSeconds: 10.0,
   ));
   $supervisor->run();

Replace the sleep loop with a real bounded event loop and stop
notification. Verify readiness, no stale generations, process reaping
and successful responses under recycle or forced kill. Autoscale
samples must contain **real** CPU, queued work, RPS and latency;
never derive scaling from constant demonstration values.

.. code-block:: console

   php tests/smoke/native_supervisor_smoke.php
   php tests/smoke/native_autoscale_http_traffic.php

See :doc:`supervision-handbook-3.0`.

Walkthrough 6: transport and protocol choices
---------------------------------------------

**Framed TCP/Unix.** Streams yield arbitrary byte fragments rather than
logical records. For a line-delimited service, use a ``LineCodec``:

.. code-block:: php

   <?php

   declare(strict_types=1);

   require __DIR__ . '/vendor/autoload.php';

   use Infocyph\Runwire\Protocol\FramedConnection;
   use Infocyph\Runwire\Protocol\LineCodec;
   use Infocyph\Runwire\Runtime;
   use Infocyph\Runwire\StreamServer;

   $server = StreamServer::tcp(
       '127.0.0.1:9000',
       static fn(): LineCodec => new LineCodec(),
       static function (string $frame, FramedConnection $connection): void {
           $connection->send('echo:' . $frame);
       },
   );

   Runtime::create()->listen($server)->run();

In another terminal send a newline-terminated frame:

.. code-block:: console

   printf 'hello\n' | nc 127.0.0.1 9000

For an IPC endpoint use Unix socket paths and apply filesystem
permissions; a Unix path alone does not authenticate every peer.

**UDP.** Datagram delivery can be lost, duplicated and reordered. Use a
``DatagramServer`` only when the application accounts for this behavior;
an ordinary UDP listener is not QUIC. The portable acceptance fixture
performs a real UDP round trip.

**WebSocket.** A native H1 upgrade requires an origin policy, client
masking, bounded fragmentation and proper close handling. Verify real
frames with ``tests/WebSocketOutboundClientTest.php`` and
``tests/WebSocketBackpressureAcceptanceTest.php``; do not infer
H2/H3 extended-CONNECT support.

**HTTP/2.** Streams share a connection but have independent cancellation
and flow credit. On GOAWAY stop accepting new streams; drain admitted
ones within a bounded lifetime. Use the independent hyper-h2 workflow
and the focused H2 tests for peer proof.

**HTTP/3.** Requires actual QUIC and TLS support. Never fake it with
plain UDP or silently fall back to H2/H1 after a failed H3 policy.
WebTransport is not included in Runwire 3.0.

See :doc:`protocols-3.0` and
:doc:`protocol-failure-atlas-3.0` for detailed frame-level decisions.

Production verification checklist
---------------------------------

#. Pin the source SHA, PHP runtime, active extensions and selected host.
#. Decide who owns listeners, accepted connections, tasks and workers.
#. Set queue, buffer, body, deadline, file and process ceilings.
#. Exercise the specific feature with a real peer and negative tests.
#. Verify full response completion rather than only request admission.
#. Test cancellation, slow readers, wrong certificate and forced stop.
#. Monitor p99, successful RPS, RSS, descriptors and real process exits.
#. Re-run security, documentation, native, hosted and package QA for the
   **same source SHA** before treating the application as production-ready.

See :doc:`production-operations-3.0` for rollout and rollback and
:doc:`troubleshooting-handbook-3.0` for incident diagnosis.
