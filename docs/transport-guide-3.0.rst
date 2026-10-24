Runwire 3.0 transport and DNS guide
===================================

This page describes **unreleased development code**, not published stable
Runwire 2.1.2. Features below have different host and optional extension
requirements. Successful unit tests do not certify a 3.0 release.

Real inbound TCP, Unix and UDP
------------------------------

The portable native acceptance fixture creates a real HTTP/1.1 listener,
framed TCP listener, Unix stream listener and UDP datagram listener. It
sends actual peer traffic, checks replies and cleans up sockets.

.. code-block:: console

   composer dump-autoload --no-dev --classmap-authoritative
   php tests/fixtures/portable_native_acceptance.php

See tests/fixtures/portable_native_acceptance.php for executable
StreamServer::tcp(), StreamServer::unix(), DatagramServer::udp(), listener
and handler examples. TCP/Unix use a FrameCodecInterface. Unix socket
paths must be absolute with correct permissions; UDP is message-based and
has no stream backpressure or delivery guarantee. Each datagram must
respect admission, message-size and peer policies.

Portable Select can work without PCNTL. Native prefork workers instead
require usable PCNTL/POSIX; host-owned workers belong to their host.
Invalid endpoints or capacity exhaustion must fail explicitly.

Outgoing TCP and Unix
---------------------

Network/DialEndpoint accepts tcp://host:port and unix:///absolute/path.
Network/Dialer verifies a numeric TCP or Unix connection using nonblocking
readiness without performing synchronous hostname resolution. The numeric
dialer currently requires optional ext-sockets for connect verification.

.. code-block:: php

   use Infocyph\Runwire\Loop\SelectLoop;
   use Infocyph\Runwire\Network\Dialer;

   // Start an independent TCP listener on 127.0.0.1:8080 first.
   $loop = new SelectLoop();
   $dialer = new Dialer($loop);
   $dialer->connect('tcp://127.0.0.1:8080',
       static function ($connection, ?Throwable $error) use ($loop): void {
           if ($error !== null) {
               // Propagate failure to the request owner.
               $loop->stop();
               return;
           }
           $connection->closeGracefully();
           $loop->stop();
       });
   $loop->run();
   $dialer->close();

For actual end-to-end sockets, cancellation and resource cleanup run:

.. code-block:: console

   vendor/bin/pest tests/DialerTest.php tests/HappyEyeballsDialerTest.php

Verified outbound TLS
---------------------

TlsClientOptions carries the original peer hostname, trusted CA roots,
optional client certificate/private key and ALPN policy through
DialOptions. A successful handshake requires OpenSSL, chain and peer-name
verification and the required ALPN negotiation. TLS over Unix sockets
is not currently supported; no TLS failure may silently downgrade to
plaintext.

.. code-block:: console

   php tests/fixtures/portable_optional_protocol_acceptance.php
   php -d disable_functions=stream_socket_enable_crypto tests/fixtures/portable_optional_protocol_acceptance.php unavailable
   php -d disable_functions=stream_socket_enable_crypto tests/fixtures/no_openssl_outbound.php

The negative test cases reject unavailable TLS while retaining ordinary
TCP. They do not certify outgoing HTTP/2 or HTTP/3 client behavior.

Off-loop DNS and IPv4/IPv6 Happy Eyeballs
----------------------------------------

AsyncDnsResolver delegates A/AAAA lookup to bounded ProcessExecutor PHP
subprocesses so the reactor never falls back to blocking name resolution.
HappyEyeballsDialer stages verified IPv4/IPv6 numeric candidates over
the common Dialer, aborts losers and respects one caller timeout and
cancellation owner.

.. code-block:: php

   use Infocyph\Runwire\Executor\ProcessExecutor;
   use Infocyph\Runwire\Loop\SelectLoop;
   use Infocyph\Runwire\Network\AsyncDnsResolver;
   use Infocyph\Runwire\Network\Dialer;
   use Infocyph\Runwire\Network\HappyEyeballsDialer;

   // Start a separate TCP listener on localhost:8080 first.
   $loop = new SelectLoop();
   $executor = new ProcessExecutor($loop, 1, 4);
   $racer = new HappyEyeballsDialer(
       $loop,
       new Dialer($loop),
       new AsyncDnsResolver($executor),
   );
   $racer->connect('tcp://localhost:8080',
       static function ($connection, ?Throwable $error) use ($loop): void {
           $connection?->abort();
           // An application must report a failed dial.
           $loop->stop();
       });
   $loop->run();
   $racer->close();
   $executor->close();

.. code-block:: console

   vendor/bin/pest tests/AsyncDnsResolverTest.php tests/HappyEyeballsDialerTest.php

DNS failure, expired deadline, queued executor saturation or canceled
address races must settle once and not retain an orphan connection,
watcher or subprocess.

QUIC and HTTP/3 capability boundaries
-------------------------------------

Native QUIC is an optional UDP-based TLS 1.3 protocol requiring ext-quic
and a compatible OpenSSL build. The native HTTP/3 server is exercised
against independent real peers in the Security & Standards workflow.
Native outbound QuicHandshakeProbe and QuicClientSession are experimental
worker-owned QUIC primitives, NOT a complete outbound HTTP/3 client.

Source fixtures tests/fixtures/quic_probe_client.php and
tests/fixtures/quic_session_echo_client.php require a real peer,
appropriate endpoint options and certificate material. The reproducible
real-peer build and invocation is in
.github/workflows/security-standards.yml, not a standalone no-argument
CLI example. Native datagrams, path migration, application 0-RTT and
WebTransport are unsupported and must fail closed.

Ownership, hosts and failures
-----------------------------

Callers or execution hosts own the event loop, child executor and
connections. Cancel address races, close remaining sockets and retire
workers at request or worker shutdown. FPM, FrankenPHP, RoadRunner,
Swoole and OpenSwoole own their respective host process/reactor
lifetimes; no second supervisor may be started inside a hosted request.

Invalid endpoint, missing optional extension, TLS verification failure,
peer close, worker/connection quota exhaustion, cancellation/deadline
and unsupported QUIC feature are separate terminal outcomes.
See the coroutine-network-3.0 and host-compatibility-3.0 guides for
ownership transfers, host matrices and remaining QA limits.
