Event loops, Fibers and structured concurrency
===============================================

Two complementary concurrency models
-------------------------------------

Runwire has callback-driven event loops and a Fiber-based coroutine
runtime. Both operate on explicit readiness and cancellation; neither
converts arbitrary blocking PHP functions into asynchronous work.

A loop callback should be short: consume ready bytes, enqueue bounded
work or schedule a continuation. The coroutine scope provides a way
to structure tasks so that parent cancellation and deadlines are
propagated to child operations. Coroutines are not OS threads.

Select and host-owned event loops
---------------------------------

`LoopInterface` expresses timers, deferral, readable/writable watchers,
cancellation, loop run and stop. `SelectLoop` is a portable option.
`LoopFactory` selects loop implementations subject to actual
capabilities. `SwooleLoop` adapts to Swoole/OpenSwoole ownership. A
host-owned reactor must not be closed as a side effect of finishing
one Runwire request.

Watchers are associated with the loop and process that registered
them. Forking after registering an active watcher does not transfer its
ownership to the child. Prefer worker-local loops for native
supervision. Clean up each registration on completion.

.. code-block:: text

   external ready socket/timer
          |
          v
   loop callback (short, bounded)
          |
          +--> update state and bounded queue
          +--> resume suspended Fiber task
          +--> register next watcher
          |
   request/task scope -> completion or cancellation -> cleanup registrations

Tasks and scopes
----------------

`CoroutineRuntime` runs a root callback and creates a
`CoroutineScope`; `Task` represents scheduled work. A scope can
spawn children, join them, cancel children with an explicit reason,
and register shutdown callbacks. `Future` and `Deferred` represent
single-completion result delivery.

Synchronization primitives include `Channel`, `Mutex`,
`Semaphore` and `Barrier`. Use bounded channels to apply producer
backpressure. Do not use a mutex to guard a blocking sleep or
filesystem call while expecting the event loop to remain responsive.

.. code-block:: php

   <?php
   use Infocyph\Runwire\Coroutine\CoroutineRuntime;

   $runtime = new CoroutineRuntime();
   $result = $runtime->run(static function ($scope): string {
       $scope->sleep(0.001);
       return 'completed';
   });

Here `sleep()` is coroutine scheduling, not `usleep()` in an event-loop
callback. Check the generated :doc:`api-reference-3.0` for concrete
scope and callback types as the development API evolves.

Cancellation and deadlines
--------------------------

A timeout is not the same as cancelling a completed response. Track
which side requested cancellation, the active timeout and whether the
operation has already transferred ownership. Cancelling a DNS or TCP
dial before success must release pending sockets and timers. Cancelling
after handoff belongs to the new connection owner.

Deadline propagation should be monotonic and subordinate to the
parent's remaining budget. Child work must not outlive its request
scope unless an explicit worker-owned background handoff occurs.
Joining child tasks should capture failures and still retire sibling
resources.

Coroutine-local data
--------------------

`TaskLocal` and scope-local state are useful for tracing context,
tenant identification and request correlation. They are not substitutes
for authentication checks. Choose inheritance intentionally; a worker
background task should not accidentally inherit request secrets.
Clear context at request completion, especially under persistent
RoadRunner or FrankenPHP workers.

Waiting for network I/O
-----------------------

`Coroutine\Network\AsyncDialer` and
`Coroutine\Network\AsyncConnection` tie network operations to the
coroutine cancellation scope. Awaiting readability/writability does
not exempt an application from byte budgets: keep a bounded receive
buffer and terminate a peer that never completes a message.

Bounded asynchronous files
--------------------------

`Coroutine\Filesystem\ScopedFiles` uses the off-loop process executor
to supply task-owned file operations; `ScopedFileTransfer` manages
chunked transfers. Cancellation must retire outstanding file IPC jobs
before the owning worker exits. The implementation does not supply
kernel-native filesystem acceleration or crash-durable atomic
replacement.

Failure and resource discipline
-------------------------------

.. list-table:: Failure mode and required handling
   :header-rows: 1
   :widths: 38 62

   * - Failure
     - Response
   * - Child task raises
     - Record failure, cancel or join siblings according to scope policy
   * - Channel closes
     - Wake waiting senders/receivers; never silently drop unaccepted work
   * - Deadline expires
     - Cancel all registrations and surface terminal reason once
   * - Event loop is stopped
     - Close loop-owned watchers/timers before releasing resource handles
   * - Background task sees request exit
     - Transfer to worker scope explicitly or cancel it
   * - Process forks while task active
     - Reject inherited active executor/dialer/pool use; create child ownership
   * - Callback throws during cleanup
     - Attempt other independent resource retirement and report failure

Observe scheduling
------------------

`CoroutineDiagnosticsSnapshot` and loop diagnostics can help identify
suspension count, runnable backlog and unexpectedly retained watchers.
Track p99 *event-loop delay* separately from end-to-end request
latency. High per-callback CPU may starve all tasks even when the
network transport is nonblocking.

Reproduce scheduler behavior with focused tests and then real peers:

.. code-block:: console

   vendor/bin/pest tests/BatchLCoroutineSchedulerTest.php
   php tests/smoke/native_cleanup_fault_smoke.php

See :doc:`coroutine-network-3.0`, :doc:`filesystem-handbook-3.0`
and :doc:`runtime-lifecycle-3.0`.


A complete coroutine scheduling and cancellation exercise
---------------------------------------------------------

**What:** ``CoroutineRuntime`` runs a scope and permits cooperative
suspension. **Why:** a Fiber can pause without blocking an entire
reactor. **When:** use it for task composition whose operations
explicitly understand cancellation; ``sleep()`` here is the coroutine
scheduler's delay, unlike synchronous ``usleep()``.

Save as ``coroutine.php`` in the project root:

.. code-block:: php

   <?php

   declare(strict_types=1);

   require __DIR__ . '/vendor/autoload.php';

   use Infocyph\Runwire\Coroutine\CoroutineRuntime;

   $coroutines = new CoroutineRuntime();
   $result = $coroutines->run(static function ($scope): string {
       $scope->sleep(0.001);
       return 'completed';
   });

   if ($result !== 'completed') {
       throw new RuntimeException('Coroutine did not finish');
   }
   echo $result, PHP_EOL;

Run ``php coroutine.php`` and then the scheduler regression suite:

.. code-block:: console

   vendor/bin/pest tests/BatchLCoroutineSchedulerTest.php

**Cancellation decisions:** when a child scope is canceled, stop new
submissions and retire its watchers before the parent finishes.
Transferring a live network connection to another owner requires an
explicit quiescent handoff; merely disposing the Fiber adapter does
not transfer or close socket ownership. A thrown callback should not
leave unrelated requests suspended. Ordinary blocking disk, DNS and
sleep calls still block PHP unless delegated to the supported off-loop
executor or coroutine-aware operation.

Read :doc:`coroutine-network-3.0` for scoped sockets and adoption.
