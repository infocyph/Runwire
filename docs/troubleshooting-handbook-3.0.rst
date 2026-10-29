Troubleshooting cookbook and failure recovery
==============================================

Start with the failing phase, not the exception text
----------------------------------------------------

When a native worker or external client fails, first identify the
runtime driver and the exact stage: bind, DNS, connect, handshake,
protocol parser, admission, application callback, output queue,
drain or process cleanup. The same transport exception can occur
for different causes before and after a request is admitted.

For every incident, capture source SHA, PHP version/extensions,
host driver, current listener, worker generation, relevant
deadlines and an independently reproduced client exchange.
Omit tokens, cookies and private keys from diagnostics.

Problem: the listener starts but nothing responds
-------------------------------------------------

Confirm the process bound the intended interface and port and is
*ready* rather than merely alive. Verify firewall and proxy forwarding,
native versus host process ownership, application startup failures
and whether connection admission is suspended. Under prefork,
inspect readiness timeout and both active/current slot ownership
rather than looking only at PID count.

Problem: FPM or shared hosting cannot run native supervision
------------------------------------------------------------

Native prefork requires PCNTL/POSIX and permission to maintain
processes. An FPM request or restricted cPanel tenant often lacks
those capabilities. Do not retry by creating another long-lived
supervisor inside the request. Use a supported host-owned or
portable mode, or deploy a separate permitted CLI service.

Problem: HTTP/3 or QUIC unavailable
-----------------------------------

Check ext-quic/OpenSSL version and actual native QUIC capabilities,
TLS certificate and ALPN, UDP port binding, and firewall path.
Test with an independent QUIC peer. Do not silently replace
mandatory H3 with HTTP/1.1 or pretend ordinary UDP is QUIC.
WebTransport is not included in Runwire 3.0.

Problem: TLS works directly but fails behind a proxy
----------------------------------------------------

Differentiate a cleartext HTTP CONNECT tunnel from an HTTPS proxy.
After cleartext CONNECT, the client must still verify the **original
origin** SNI, chain and ALPN. HTTPS proxy to HTTPS origin requires
nested TLS-over-TLS, which Runwire intentionally rejects. A
certificate for the proxy must not be accepted as a certificate
for the target service.

Problem: DNS intermittently blocks or times out
-----------------------------------------------

Check off-loop resolver process worker availability, queue depth,
DNS answers, per-host address race limits and pending cancellation.
Never let a callback fall back to blocking `gethostbyname()` in the
reactor. Record the actual connected address and re-validate IP
allowlists before trusting the connection.

Problem: HTTP/2 sibling streams fail during shutdown
-----------------------------------------------------

Check the last accepted stream ID sent with GOAWAY, whether each
request's headers were fully admitted, connection/window credit,
cancellation handling, final response bytes and drain deadline.
Client-side HEADERS transmission alone is not evidence of server
admission. For a strong test, require server response headers
on each stream before triggering stop and verify byte-exact bodies.

Problem: WebSocket handshake succeeds but messages stall
--------------------------------------------------------

Review negotiated subprotocol, masking direction, continuation and
control frames, message ceiling and application write queue.
A successful 101 Switching Protocols exchange does not guarantee
the frame parser or outbound writer remains healthy.

Problem: native worker count fluctuates
---------------------------------------

Inspect CPU/queue/successful-RPS/p99 sample shape and validity,
hysteresis windows, configured cooldown and operator override.
A missing sample should not force arbitrary scale-out or scale-in.
Use supervisor status to find duplicate current slot claims and
unreaped old generations.

Problem: memory rises despite low PHP heap
------------------------------------------

Report *process-tree RSS*, not only `memory_get_usage()`. Include
native extension buffers, QUIC/session state, child executor
processes, pending queues and idle sockets. Distinguish retained
capacity from unbounded growth by comparing measurements over
long soak intervals.

Problem: file I/O freezes all requests
--------------------------------------

Verify that synchronous filesystem work is not running on the
reactor and that the bounded `ProcessExecutor` can launch PHP CLI
subprocesses. Inspect pending jobs and IPC pipe watchers. The
file facade is not a kernel async backend or sandbox.

Problem: CI is red after a dependency update
--------------------------------------------

Do not disable Composer audit, pin a vulnerable PHPUnit or silence
PHPForge Pint/PHPCS/PHPStan/Psalm rules. Read the *first actual error*
in each workflow: dependency resolution, static analysis, protocol
correctness and sustained diagnostics are distinct failures.

If a strict PHPCS rule now covers internal native test fixtures,
repair the fixtures without changing what the test proves. Test
runners must not return into inherited forked child execution
paths. Maintain independent H2/H3 peer evidence and do not
mark tests green based on skipped or cancelled jobs.

Debugging flow
--------------

.. code-block:: text

   classify runtime / protocol / phase
          |
   identify actual owner and current generation
          |
   identify admitted versus merely attempted requests
          |
   reproduce with minimal independent peer
          |
   inspect cleanup and bounded queues
          |
   run focused regression on exact SHA
          |
   run full PHPForge + host + native protocol QA
          |
   update evidence and rollback decision

Quick reference commands
------------------------

.. code-block:: console

   php -v
   php -m
   composer validate --strict
   php tests/fixtures/portable_native_acceptance.php
   php tests/smoke/native_listener_fork_smoke.php
   php tests/smoke/native_cleanup_fault_smoke.php

Only run native fork-specific probes where PCNTL/POSIX are available.
For automated H2/H3, use independent reference and native security
workflows. See :doc:`observability-handbook-3.0` and
:doc:`performance-qa-handbook-3.0`.
