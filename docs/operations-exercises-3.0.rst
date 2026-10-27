Operations exercises: worker failures, rolling reload and rollback
=================================================================

Audience and release boundary
-----------------------------

This is a hands-on operator field manual for **unreleased Runwire 3.0**.
The published stable package is 2.1.2. Its presence does not certify
production deployment or replace an operator-approved rollback plan.

Read :doc:`production-operations-3.0`,
:doc:`supervision-handbook-3.0` and
:doc:`troubleshooting-handbook-3.0` before enabling native prefork
or exposing an internet-facing listener.

Exercise 0: preflight the host
------------------------------

.. code-block:: console

   php -v
   php -m
   composer validate --strict
   php tests/fixtures/portable_native_acceptance.php

Record the checkout SHA, effective php.ini, loaded extensions,
chosen runtime driver, privileges, TCP/UDP port permissions,
certificate renewal, log destination and temporary directory.
PCNTL/POSIX and permitted fork are prerequisites for native worker
supervision, not optional performance hints. QUIC/H3 requires its
actual native backend; cPanel and shared hosting may forbid it.

Exercise 1: readiness is not liveness
-------------------------------------

**Liveness** means a process or reactor exists and can make progress.
**Readiness** means the application, listener and required dependencies
are prepared to accept work. A freshly forked PID is not necessarily
ready. Replacement generations must report readiness before the
old generation is removed from service.

Check worker group, current PID, slot, generation, readiness deadline,
restart budget and supervisor event feed. Missing readiness should
trigger a visible failure instead of quietly marking the worker alive.

Exercise 2: crash versus graceful stop
--------------------------------------

The native fault probes create actual child processes:

.. code-block:: console

   php .ci/c3_supervisor_smoke.php
   php .ci/c3_supervisor_fault_smoke.php
   php .ci/c3_concurrent_fault_smoke.php

A SIGKILL is an abrupt process failure; work may be lost.
A graceful stop blocks new admission and drains existing accepted work
within deadlines. Forced termination is not a successful drain.

A rehearsal should:

#. Start a native worker group and observe ready, generation and PID.
#. Confirm real peer responses, not just a listening socket.
#. Kill a **worker** and observe bounded restart/backoff.
#. Trigger rolling reload; wait for replacement readiness.
#. Recycle a current worker and inspect slot uniqueness.
#. Request graceful stop with an in-flight response.
#. Verify all children are reaped and owned resources retired.
#. Repeat with an exhausted restart budget and verify explicit failure.

Never direct destructive signals at unrelated production processes
when exercising these CLI fixtures.

Exercise 3: autoscale stability
-------------------------------

Autoscale needs valid CPU, queue, successful RPS and p99 samples.
Missing telemetry must not authorize scale-out. Bounds, hysteresis
and cooldown prevent rapid worker-count oscillation; scale-in must
respect admission and drain.

.. code-block:: console

   php .ci/c3_autoscale_window_contract.php
   php .ci/c3_autoscale_http_traffic.php
   php .ci/c3_supervisor_soak.php

The native HTTP fixture accounts for all requests attempted after
readiness and checks real successful responses and non-overlapping
current worker slots. Short or 120-second tests are useful for
C3, but cannot replace the **1800-second** U15 release soak.

Exercise 4: resource-retirement inventory
------------------------------------------

Keep an ownership ledger covering parent listener, child event loop,
accepted stream, TLS/H2/H3 session, pending DNS/dial, Happy Eyeballs
race, pool lease, QUIC stream, process executor child, background
coroutine, periodic timer and IPC wake watcher.

.. code-block:: console

   php .ci/c3_listener_fork_smoke.php
   php .ci/c3_dialer_smoke.php
   php .ci/c3_cleanup_fault_smoke.php
   php .ci/c3_filesystem_smoke.php

A failing timer cancellation must not skip unrelated cleanup.
Measure resource use for the *entire process tree*; a flat parent
RSS may hide growing child memory or leaked subprocess handles.

Exercise 5: operational action matrix
-------------------------------------

.. list-table:: Process operations and their meaning
   :header-rows: 1
   :widths: 23 41 36

   * - Action
     - Intended result
     - Required check
   * - Graceful stop
     - Stop admission then drain
     - Full response bodies or explicit deadline failures
   * - Forced stop
     - Terminate promptly
     - Accepted in-flight loss acknowledged
   * - Rolling reload
     - Replace old generation
     - Replacement ready before old retirement
   * - Recycle
     - Replace bounded-age/current worker
     - One current owner per slot
   * - Autoscale
     - Grow/shrink from valid samples
     - No sub-cooldown flap or duplicate current slots
   * - Rollback
     - Restore known working release
     - Real peer correctness after restore

An orchestration layer such as systemd/Kubernetes/FPM has its own
termination clock. Align it with Runwire's grace settings; a
shorter external kill timeout can defeat application drain.

Exercise 6: incident evidence
-----------------------------

Record these fields without leaking confidential material:

.. code-block:: text

   exact Runwire SHA and deployed package version
   host, PHP version, extension and driver
   listener address, original hostname, ALPN and TLS verification status
   parent/child PID, generation and current/retiring worker slot
   request attempts, admitted streams, complete responses, aborts
   pending buffers, executor queue, connection limits and autoscale samples
   CPU, RSS, FD and loop p50/p95/p99 over time
   termination signal, drain start, deadline and final child cleanup

Never record private keys, Authorization values, bearer tokens,
cookies or proxy credentials in broadly accessible artifacts.

Exercise 7: rollback triggers
-----------------------------

A migration candidate should be rolled back when it violates TLS or
origin trust, loses accepted responses under a promised graceful
drain, allows overlapping current worker owners, spins in unbounded
restart loops or grows process-tree resources without bounds.

Preserve the failed experiment, restore the immutable published
working release/configuration and verify independent peer correctness
again. Rollback is a planned operational response, not proof that
the failed candidate passed certification.

See :doc:`protocol-failure-atlas-3.0` for stream-specific triage,
and :doc:`performance-lab-3.0` for the genuine C4 release metrics.
