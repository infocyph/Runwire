Native supervision, autoscaling and worker recovery
===================================================

Responsibilities of the master
------------------------------

The native `Supervisor` is a *process-lifecycle owner*, not an HTTP
request dispatcher. It launches configured worker groups, receives
readiness and lifecycle notices, tracks current PID/generation by slot,
responds to signals, applies restart budgets, performs rolling reload
and retires workers under graceful and forced stop rules.

A host-owned runtime is different. Under FPM, RoadRunner, FrankenPHP
or Swoole the host may own worker processes or the reactor. Do not
start Runwire's native prefork supervisor inside a host request merely
to obtain autoscale behavior.

Starting a worker group
-----------------------

The public `WorkerGroup` constructor accepts `name`, `count`,
`bootstrap`, `restartPolicy`, `recyclePolicy`,
`admissionPolicy`, `privilegeDropPolicy`, `automaticReady`,
`readyTimeoutSeconds`, `shutdownTimeoutSeconds`,
`reloadable` and `role`. The bootstrap callback receives a
`WorkerContext`.

.. code-block:: php

   <?php
   use Infocyph\Runwire\Supervisor\Supervisor;
   use Infocyph\Runwire\Supervisor\WorkerContext;
   use Infocyph\Runwire\Supervisor\WorkerGroup;

   $supervisor = new Supervisor();
   $supervisor->group(new WorkerGroup(
       name: 'worker-jobs',
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

This is a minimal worker-lifecycle example, **not** a recommended busy
polling production job loop. Real asynchronous workers should integrate
a loop, stop wake stream, periodic timers and bounded work queues. The
native smoke fixtures include this lifecycle in an independently
forked process; they require PCNTL/POSIX and must never run under an
ordinary web request.

Readiness and generation handoff
--------------------------------

Master process and child process have different ownership rules.
A child signals readiness only when its application, listeners and
required dependencies have started. The master tracks a current worker
PID for each group slot, with distinct generation identifiers.
Starting a replacement process is not sufficient to promote it; a
readiness timeout must produce an observable failure.

On rolling reload, the master starts replacement generation workers,
waits for readiness, then retires old workers. The old generation may
continue serving accepted work during the overlap, but a new generation
must not make two workers simultaneously claim the same *current*
slot. Limit surge and stop deadlines according to policy.

Restart, recycle and shutdown
-----------------------------

.. list-table:: Action, intention and guarantee boundary
   :header-rows: 1
   :widths: 22 38 40

   * - Action
     - Expected behavior
     - Failure semantics
   * - Worker crash/SIGKILL
     - Reap and conditionally restart within budget
     - In-flight work can be lost; not graceful
   * - Controlled recycle
     - Replace an aging/unhealthy worker with readiness handoff
     - Bounded grace then forced retirement
   * - Rolling reload
     - Start new generation and drain old
     - New generation must be ready first
   * - Graceful stop
     - Stop accepting then drain
     - Completes admitted work only within deadlines
   * - Force stop
     - Signal termination/kill promptly
     - Aborts remaining sessions and background work
   * - Restart budget exhaustion
     - Stop uncontrolled crash loops
     - Surface failure; do not spin forever

The `RestartPolicy` bounds restarts and backoff. The
`ReloadPolicy` controls replacement readiness and safe handoff.
The `WorkerRecyclePolicy` can limit request count, uptime and memory.
The `SupervisorEventType` catalog provides granular lifecycle events.
Inspect :doc:`api-reference-3.0` for supported properties and
event callback signatures.

Autoscaling is real native process scaling
------------------------------------------

The 3.0 supervisor drives physical scale-out and scale-in, not merely
calculating a target. Its `SupervisorAutoscaler` internal coordinator
samples policy metrics and can fork additional child workers or retire
surplus slots through the parent. It rejects invalid samples rather
than inventing a healthy workload signal.

Use a monitored `AutoscalePolicy`, not an unbounded auto-increment.
The sample callback returns:

* `cpuRatio`: finite fractional CPU utilization;
* `queued`: nonnegative pending work count;
* `successfulRps`: successful request completions per second;
* `p99Milliseconds`: finite response-tail latency.

.. code-block:: php

   <?php
   use Infocyph\Runwire\Supervisor\AutoscalePolicy;

   $policy = new AutoscalePolicy(
       minWorkers: 2,
       maxWorkers: 4,
       scaleOutQueueDepth: 8,
       sampleSeconds: 2.0,
       cooldownSeconds: 6.0,
   );

   // Only after group 'worker-jobs' has been registered:
   $supervisor->autoscale(
       'worker-jobs',
       $policy,
       static fn(): array => [
           'cpuRatio' => 0.10,
           'queued' => 0,
           'successfulRps' => 0.0,
           'p99Milliseconds' => 1.0,
       ],
   );

The callback here returns illustrative constant telemetry, **not** real
production instrumentation. Constant low values can cause scale-in;
feed a real bounded metrics provider, ensure its sampling window is
long enough, and avoid mixing cumulative counters with rates. Missing
telemetry should leave the system safe, not trigger arbitrary kill/fork.

Hysteresis and cooldown
-----------------------

The controller uses repeated high/low pressure windows and monotonic
cooldown to prevent oscillation. Scale-out and scale-in must remain
bounded by the configured min/max and maximum step. A fixed operator
override intentionally takes precedence inside its permitted range.
Graceful scale-in must retire workers without admitting replacement
work into a conflicting current-owner slot.

The C3 independent native tests verify real worker transitions,
SIGKILL recovery, concurrent autoscale and reload, exact HTTP
completion counts, and no back-to-back cooldown flapping.

Worker-owned background resources
---------------------------------

`WorkerContext` exposes explicit ownership methods for connections,
dialers, DNS/Happy Eyeballs dialers, pools, QUIC sessions and process
executors. It also offers `every()` for periodic tasks and
`spawnBackground()` for worker-owned coroutines.

Resources owned by one request need not survive that request, but
worker-owned background tasks may continue until worker drain.
During stop, reject new background submissions, cancel periodic
callbacks, complete or cancel pending I/O, and preserve a stop
notification even when one cancellation observer throws.
Do not close a Swoole-owned loop from a worker whose loop does not
belong to it.

Diagnostics and operational decisions
-------------------------------------

`Supervisor::status()` returns a structured snapshot, including live
workers, generations and pending restarts. The application should
record worker events and expose alarms for:

* repeated crash/restart and restart budget exhaustion;
* failed ready handshake or prolonged reload;
* worker count stuck outside desired min/max;
* unexpectedly high pending dial/executor/connection counts;
* frequent recycler activity, retained sockets and RSS growth;
* timeouts between stop request and confirmed child reaping.

Never report **zero errors** solely from a final empty worker map:
the system could have lost admitted work before shutdown. Check
client-level correctness as well.

Validation before production
----------------------------

.. code-block:: console

   php .ci/c3_supervisor_smoke.php
   php .ci/c3_supervisor_fault_smoke.php
   php .ci/c3_concurrent_fault_smoke.php
   php .ci/c3_supervisor_soak.php
   php .ci/c3_autoscale_http_traffic.php

These tests use real Linux/POSIX processes and do not run under FPM.
They provide C3 proof, not U15/U17 release certification. The native
20–120 second gates are not a substitute for an independent
1,800-second release soak. See :doc:`performance-qa-handbook-3.0`
for this distinction and :doc:`production-operations-3.0` for a
safe restart playbook.
