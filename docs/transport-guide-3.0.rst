Transport and DNS guide
=======================

Features below have different host and optional extension
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

Outbound numeric TCP and Unix dials create a fresh PHP stream context for
each connection. They do not inherit a process-global default SSL
``local_cert``, ``cafile`` or peer-policy value from a previous request.
An isolated subprocess regression deliberately poisons default SSL context
with a missing certificate and confirms that explicitly trusted TLS remains
independent while preserving strict peer verification.

.. code-block:: console

   vendor/bin/pest tests/DialerContextIsolationTest.php

Off-loop DNS and IPv4/IPv6 Happy Eyeballs
------------------------------------------

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
CLI example. Native QUIC datagrams, path migration and application 0-RTT are
unsupported and must fail closed. The separate :doc:`features`
limitations section documents excluded 3.0 protocol scope.

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

Optional public-address SSRF restrictions (3.0 development)
-----------------------------------------------------------

OutboundAddressPolicy is opt-in on DialOptions(addressPolicy: ...).
It rejects private, reserved, loopback, link-local and mapped loopback IPs
**before** opening a numeric outgoing TCP socket, and compares the
kernel-reported remote IP against the exact validated DNS candidate
**after connect but before TLS and ownership handoff**. HappyEyeballsDialer
passes the same policy through all A/AAAA candidate attempts. An IP-based
policy rejects Unix-domain endpoints rather than pretending to validate
filesystem paths. Applications reaching trusted internal systems must not
enable this public-only policy and should use a separate explicit allowlist.

No implicit SSRF protection is asserted for dial calls without a policy;
HTTP redirect and cross-origin credential policies remain independent.

QUIC worker write-pressure regression evidence
----------------------------------------------

The grouped QUIC transport tests use a realistic prefix-accepting native
stream fake to verify non-FIN partial writes, continuation of only the
remaining bytes, sibling stream isolation, four-slot turnover across 24
rounds and empty-FIN EOF reuse. The independent aioquic fixture separately
validates real TLS/ALPN, large simultaneous bidirectional echoes, native
stream reset and subsequent unidirectional FIN. Mock tests cannot establish native loss/reorder, live flow-control windows,
fair transport scheduling or cancellation-storm recovery on their own.
Use the independent native-QUIC interoperability and impairment tests
for those specific contracts; no test proves arbitrary future networks.

Independent QUIC sibling reset burst
--------------------------------------------------

The optional native QUIC CI matrix tests a verified TLS 1.3/ALPN session
against an independently implemented aioquic echo peer. Two >32 KiB
bidirectional streams complete byte-perfectly before a bounded six-sibling
open/reset burst, followed by an independently observed unidirectional
stream FIN. JSON artifacts are checked on PHP 8.4 and 8.5. This verifies
worker/session ownership across repeated native reset operations; it does
**not** substitute for network loss/reorder injection, datagram support,
full flow-control stalls or release throughput budgets.

After the six native resets, the same worker-owned, verified QUIC session
also starts two fresh concurrent bidirectional streams and echoes their
entire payloads before the final independent unidirectional FIN. Native
PHP 8.4 and 8.5 artifacts must report both post-reset successes. This
regression checks sibling recovery; it is not a simulated loss network.


Independent native QUIC packet impairment
-----------------------------------------

Independent aioquic now optionally drops every nineteenth and delays every
eleventh post-TLS UDP ingress packet. The actual PHP8.5 native worker must
still complete six resets, two recovered bidirectional echoes and uni FIN.
CI archives independent nonzero dropped/delayed datagram counters.
This scoped packet-impairment run does not guarantee recovery from every
client-side loss, stalled flow window or future cancellation storm.
