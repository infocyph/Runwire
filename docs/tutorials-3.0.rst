Runwire 3.0 executable recipes and troubleshooting
==================================================

Published stable Runwire 2.1.2 is distinct from the unreleased 3.0
development branch. Run these recipes in a development checkout on
PHP 8.4 or 8.5. Obtain the project Composer autoloader first.

Portable inbound HTTP, TCP, Unix and UDP
----------------------------------------

This real acceptance fixture binds independent listeners, exercises
real peer packets and validates replies before cleaning up sockets.

.. code-block:: console

   composer install --no-interaction --no-progress
   php tests/fixtures/portable_native_acceptance.php

The portable Select mode works without PCNTL or POSIX. A Unix-domain
path still requires correct host permissions and cleanup; UDP has no
stream delivery or ordering guarantee. If a listener cannot bind,
inspect port conflicts, permissions and actual PHP stream transports.

Outgoing TCP, TLS and DNS
-------------------------

Use the nonblocking numeric Dialer for validated IP/Unix addresses
and AsyncDnsResolver plus HappyEyeballsDialer for hostnames. A
bounded ProcessExecutor is required for off-loop A/AAAA resolution.

.. code-block:: console

   vendor/bin/pest tests/DialerTest.php tests/AsyncDnsResolverTest.php
   vendor/bin/pest tests/HappyEyeballsDialerTest.php tests/TlsClientDialerTest.php

A numeric stream dial needs the optional sockets extension for
connected-socket verification. TLS requires usable OpenSSL, original
peer hostname and an explicit secure trust policy. Do not retry as
plaintext after a failed certificate or ALPN negotiation. DNS worker
saturation, timeout or cancellation must be surfaced to the owner.

Bounded asynchronous files and streaming
----------------------------------------

The async file facade delegates blocking filesystem calls to bounded
PHP CLI worker processes. It does not provide kernel-native AIO or
crash-durable atomic replace.

.. code-block:: php

   use Infocyph\Runwire\Executor\ProcessExecutor;
   use Infocyph\Runwire\Filesystem\AsyncFileSystem;
   use Infocyph\Runwire\Loop\SelectLoop;

   $loop = new SelectLoop();
   $executor = new ProcessExecutor($loop, 2, 32);
   $files = new AsyncFileSystem($executor);
   $files->read('/srv/app/data.bin', 4096, static function (string $bytes, ?Throwable $error): void {
       // Handle the failure before consuming the bytes.
   });
   $executor->onDrained(static function () use ($executor): void {
       $executor->close();
   });
   $loop->run();

.. code-block:: console

   vendor/bin/pest tests/AsyncFileSystemTest.php tests/ScopedFileTransferTest.php

If process-pipe creation fails, check PHP CLI availability, process
permissions and configured worker limits rather than claiming
transparent nonblocking disk I/O. Cancel all file jobs and close
the borrowed executor at worker shutdown.

Real hosted request and worker lifecycles
-----------------------------------------

FPM requests must not retain sockets or temporary files across
requests. Persistent FrankenPHP and RoadRunner workers must isolate
request state while retaining only deliberately worker-owned resources.
Swoole/OpenSwoole own the reactor; a Runwire host adapter must not
create a competing loop. The real CI fixtures are:

.. code-block:: console

   php tests/fixtures/roadrunner-live/index.php
   php tests/fixtures/fpm-live/index.php
   php tests/fixtures/swoole_worker_acceptance.php

The fixtures above require their actual host environment or host
server launch settings; they are source references, not instructions
to run an FPM or RoadRunner host using plain CLI directly. Use
.github/workflows/real-host-acceptance.yml and
.github/workflows/swoole-coroutine.yml to reproduce host setup.
Follow exact CI container/service configuration for each PHP version.

Troubleshooting checklist
-------------------------

For event-loop starvation, inspect queued executor work, stalled
downstream peers, watcher counts and native loop selection.
For unexplained TLS failures, verify hostname, certificate chain,
ALPN and OpenSSL availability rather than disabling peer validation.
For incorrect host behavior, distinguish request scope from worker
scope and native prefork mode from host-managed application workers.
For QUIC failures, verify ext-quic/OpenSSL compatibility, the peer's
TLS 1.3 ALPN and native datagram limitations. Record reproduction
command, PHP version, extension versions, workload shape and the
exact commit SHA before reporting a regression.
