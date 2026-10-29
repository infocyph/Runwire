Production operations and deployment runbooks
=============================================

Deployment prerequisites
------------------------

This guide covers Runwire 3.0 deployment, staged rollout, monitoring
and rollback. Validate host features and application-specific behavior
before exposing network-facing listeners.

A platform operator must establish runtime capability, workload limits,
trust boundaries and rollback authority before exposing a listener.

Host selection matrix
---------------------

.. list-table:: Runtime models and operational responsibilities
   :header-rows: 1
   :widths: 22 36 42

   * - Host
     - Who owns the runtime?
     - Operational consideration
   * - Native PHP CLI
     - Runwire event loop, optionally native supervisor
     - PCNTL/POSIX for prefork, explicit signal/drain management
   * - Portable PHP CLI
     - Runwire Select-based event loop
     - No native prefork promise without PCNTL
   * - PHP-FPM
     - FPM process manager and request lifecycle
     - Do not start a resident Runwire supervisor per request
   * - FrankenPHP
     - Persistent host worker context
     - Separate request and worker-owned state
   * - RoadRunner
     - RoadRunner process and request dispatcher
     - Respect worker lifetime, reset request-scoped context
   * - Swoole/OpenSwoole
     - Existing host reactor and coroutine context
     - Do not create or terminate a competing reactor
   * - Shared hosting or cPanel
     - Hosting account restrictions
     - Probe extension, process, signal, port and daemon permissions

Do not assume an extension can be loaded merely because a protocol
name is configured. Native HTTP/3 needs an available compatible
QUIC/OpenSSL stack; many shared hosts lack it. Plain HTTP/1.1,
portable TCP/UDP and host-owned features should be evaluated
independently.

Preflight checklist
-------------------

1. Capture exact commit SHA and PHP version (8.4 or 8.5 for the current
   development matrix), OS/architecture and installed extensions.
2. Validate Composer's release dependency constraints without
   disabling security advisory enforcement.
3. Verify network binding, firewall and reverse-proxy / LB policy.
4. Supply appropriate certificates, CA bundles and key permissions;
   fail deployment on invalid trust configuration.
5. Set request/header/stream/connection/socket/worker memory limits.
6. Confirm application initialization, warmup, readiness and drain
   in the selected runtime model.
7. Run the exact native, host-specific, PHPForge and independent
   protocol QA on the staged source SHA.
8. Save a last-known-good baseline and a verified rollback path.

.. code-block:: console

   php -v
   php -m
   composer validate --strict
   composer install --no-interaction --prefer-dist --no-progress

The commands inspect the installed environment. A clean Composer
install does not prove ext-quic or POSIX signal support.

Deployment topology
-------------------

.. code-block:: text

   External clients
        |
   TLS termination / load balancer (policy owner)
        |
   Optional reverse proxy for HTTP1/H2
        |
   Runwire listener(s)
        |
   worker group -> application -> outbound DNS/TLS/HTTP / file executor
        |
   telemetry sink / structured error reporting

If TLS is terminated by a reverse proxy, define trusted headers
carefully. Do not trust arbitrary client-supplied forwarded headers
as authority to change HTTPS origin identity or logins. Preserve
client IP/rate-limit semantics when behind a proxy.

For HTTP/3, the QUIC listener may need direct UDP exposure and ALPN
routing, unlike TCP proxy termination. A generic HTTP reverse proxy
does not automatically pass an H3 QUIC session through to PHP.

Environment and filesystem
--------------------------

Use a dedicated unprivileged service account and minimum writable
paths. Credentials should be injected by the deployment platform,
not baked into repository fixtures, benchmark logs or documentation.
Rotate TLS certificates before expiry and restart/reload with safe
worker handoff when necessary.

The process executor spawns separate PHP workers; validate allowed
functions, process quotas, executable PHP path, temporary directory
permissions and available file descriptors. Shared hosting may
disallow `proc_open` or background daemons. In that case, report
unsupported features rather than blocking the reactor.

Health, readiness and liveness
------------------------------

Liveness means a process exists and can make progress; readiness means
it can accept useful work. A process can be alive but overloaded,
lacking an upstream TLS dependency, or in graceful drain.

Watch readiness handshakes, queued work, pressure/saturation,
successful-RPS, tail latency, process restarts, resource counts and
explicit application health. Use admission/backpressure for load
shedding instead of allowing memory to grow without bound.

Autoscaling runbook
-------------------

Before enabling autoscale, benchmark a known worker count against
real workload and resource budgets. Feed valid *windowed* CPU,
queue length, successful throughput and p99 telemetry. Maintain
minimum capacity and a cooldown that prevents flapping. Test an
operator-fixed override and missing telemetry.

During scaling, check that new workers signal ready and old workers
drain; ensure that the same current slot is not owned twice.
Do not assume adding workers improves throughput if upstream
connections or file executor workers remain bottlenecks.

Rolling reload runbook
----------------------

1. Check that a new source/configuration is fully staged and valid.
2. Record the source SHA and old worker generation/slots.
3. Start replacement workers and wait for readiness evidence.
4. Stop old workers from accepting new traffic.
5. Allow accepted streams (including H2/H3 siblings) to finish
   within explicit graceful deadlines.
6. Confirm old worker PID reaped, no pending restarts or watcher leaks.
7. Compare request correctness, error counts and p99 to baseline.
8. Roll back immediately if readiness or client correctness regresses.

A reload should not be used to conceal a fork-safety or connection
ownership defect. Forced termination must be visible as a separate
event.

Incident and rollback runbook
-----------------------------

On an incident, isolate whether the failure is protocol correctness,
capacity, trust, application behavior or host environment. Stop new
admission on unhealthy workers; preserve sanitized traces, exact
source SHA, error classification and status counters. Avoid deleting
logs while trying to free disk space without retention approval.

Rollback means restoring a separately certified runtime/package and
configuration. A branch PR is not a release artifact. Confirm that
migration has not introduced irreversible application data changes;
Runwire cannot roll back an application's database for it.

Runbook evidence
----------------

.. code-block:: console

   php tests/fixtures/portable_native_acceptance.php
   php tests/smoke/native_supervisor_fault_smoke.php
   php tests/smoke/native_concurrent_fault_smoke.php
   php tests/smoke/native_supervisor_soak.php

Run process/signal tests only where those OS capabilities exist.
Consult :doc:`observability-handbook-3.0`,
:doc:`performance-qa-handbook-3.0` and :doc:`migration-playbook-3.0`
before release decisions.


Deployment example: from preflight to a safe canary
---------------------------------------------------

**Preflight:** record the candidate SHA and PHP/extension matrix.
Choose the host owner and validate any optional TLS, QUIC, process or
event extension before enabling dependent features.

.. code-block:: console

   php -v
   php -m
   composer validate --strict
   php tests/fixtures/portable_native_acceptance.php
   vendor/bin/pest tests/TraceContextTest.php tests/OutboundAddressPolicyTest.php

**Canary:** bind a dedicated internal port, send a real successful
response and a malformed request, exercise a slow reader, then collect
completed RPS, p99, RSS, descriptors and worker generation. Only
enable native autoscale with actual CPU/queue/latency samples and
after proving worker drain/restart.

**Failure/rollback example:** if HTTP errors appear during a rolling
reload, stop admitting new work to the failed generation; verify
whether an accepted mutating request committed before resubmission,
drain the old owner to a bounded deadline, and revert the deployment
artifact/configuration. Do not infer that SIGKILL delivered responses
or that a successful TCP bind means a healthy application.

Do not run native master/fork scripts inside FPM. Operators must
separately test certificate renewal and application data rollback
because GitHub-hosted CI cannot certify those site-specific actions.
This page documents a procedure, not an automatically executed
deployment. See :doc:`operations-exercises-3.0`.
