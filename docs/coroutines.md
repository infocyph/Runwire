# Runwire 1.0 Coroutines and Structured Concurrency

Runwire 1.0 provides a lightweight coroutine runtime built on PHP `Fiber`, Runwire `LoopInterface`, and structured task ownership. The coroutine layer is intentionally low level: it coordinates Runwire-aware timers, stream readiness, cancellation, request lifecycles, worker background work, and the callback-based `Network\Connection` core. It does not transparently convert arbitrary blocking PHP APIs into asynchronous operations.

## Mental model

A `CoroutineRuntime` owns one scheduler and one concrete event loop. Every task belongs to that scheduler for its full lifetime. Work is structured through `CoroutineScope`: a scope cannot finish while owned children are still live, and request or worker shutdown cancels/drains work through the same ownership tree.

The normal ownership hierarchy is:

```text
runtime
├── request root scope
│   └── nested groups
│       └── tasks
└── worker background scope
    └── tasks
```

Runwire tasks always use the Runwire Fiber scheduler. Host runtimes may supply reactor integration, but they do not replace Runwire task semantics.

## Basic execution

```php
use Infocyph\Runwire\Coroutine\CoroutineRuntime;
use Infocyph\Runwire\Coroutine\CoroutineScope;

$runtime = new CoroutineRuntime();

$result = $runtime->run(function (CoroutineScope $scope): array {
    $left = $scope->spawn(static fn(): string => 'left');
    $right = $scope->spawn(static fn(): string => 'right');

    return [$left->await(), $right->await()];
});
```

`CoroutineRuntime::run()` owns the root scope, drives the scheduler, joins structured children, and returns or rethrows the root task result. Starting a nested `run()` on the same runtime is rejected; use the active scope instead.

## Structured ownership and failure

`CoroutineScope::spawn()` creates an owned child. `CoroutineScope::group()` creates a nested structured group and supports the configured `TaskGroupFailureMode`.

Default fail-fast behavior is:

1. the first unhandled child failure is recorded;
2. live siblings are cancelled;
3. sibling cleanup is joined;
4. the primary failure is propagated.

Use collect-all mode only when all child outcomes must be observed before the group returns.

There is no request-level detached-task escape hatch. Work intended to outlive one request belongs to a worker background scope instead.

## Cancellation and deadlines

Children inherit parent cancellation and the earliest applicable monotonic deadline. A child may tighten a deadline but cannot extend its parent deadline. Structured parent/child cancellation links are tracked separately from general cancellation observers, so task fan-out is governed by coroutine policy rather than the public observer-subscription limit.

Suspending primitives register cancellation-aware waiters and clean up losing timer/watcher/subscription paths when another completion path wins. Cancellation is represented by `CancelledException` with a `CancellationReason`, so it remains distinguishable from application failure.

Useful cooperative checkpoints include:

```php
$scope->cancellation()->throwIfCancelled();
$scope->yieldNow();
$scope->sleep(0.010);
```

Blocking CPU work is still cooperative: long loops must yield explicitly or be moved to process-level scaling/offloading.

## Request-scoped execution

For direct request integration, bind the existing `RequestContext` to `CoroutineRuntime::runRequest()` or use `Runtime\CoroutineRequestHandler`.

```php
use Infocyph\Runwire\Coroutine\CoroutineScope;
use Infocyph\Runwire\Runtime\CoroutineRequestHandler;

$handler = new CoroutineRequestHandler(
    $runtime,
    function ($request, $writer, CoroutineScope $scope): void {
        $profile = $scope->spawn(static fn() => loadProfile());
        $settings = $scope->spawn(static fn() => loadSettings());

        $writer->end(json_encode([
            'profile' => $profile->await(),
            'settings' => $settings->await(),
        ], JSON_THROW_ON_ERROR));
    },
);
```

Request completion remains ordered so structured coroutine work drains before request resetters and final `RequestContext` completion. Persistent hosts therefore cannot carry request-owned tasks, task-local state, timers, stream watchers, or request cancellation subscriptions into the next request.

FPM, RoadRunner, and FrankenPHP may use an execution-local Runwire coroutine runtime when explicitly invoked. Their host lifecycle remains authoritative; Runwire does not claim that arbitrary host I/O becomes asynchronous.

## Worker background work

Longer-lived work belongs to task/service worker generations through `WorkerContext::spawnBackground()`. Attach the worker loop first.

```php
use Infocyph\Runwire\Coroutine\CoroutineScope;

$worker->attachLoop($loop, backgroundShutdownGraceSeconds: 5.0);

$worker->spawnBackground(function (CoroutineScope $scope): void {
    while (true) {
        $scope->cancellation()->throwIfCancelled();
        processOneUnit();
        $scope->yieldNow();
    }
});
```

When drain/reload/shutdown begins, the worker stops admitting new background work, cancels/drains generation-owned tasks within the configured grace period, and prevents old-generation tasks from surviving into a replacement generation.

`WorkerContext::backgroundCoroutineDiagnostics()` exposes the bounded scheduler snapshot for a configured background scope. `backgroundTaskCount()`, `backgroundDrainExpired()`, and `acceptingBackgroundWork()` remain the small lifecycle-oriented helpers.

## Channels and synchronization

Runwire provides scheduler-aware synchronization primitives through the active scope:

- `channel($capacity)` — FIFO rendezvous at capacity `0`, bounded buffering above `0`;
- `semaphore($permits)` — bounded FIFO permit control;
- `mutex()` — single-owner locking with cancellation-safe waiting;
- `barrier($parties)` — explicit generation-based phase coordination;
- `deferred()` / `Future` — one-shot producer/consumer completion;
- `TaskLocal` — task-local context inherited according to Runwire task rules.

Channels preserve all PHP payload values, including `false`, `null`, `0`, and empty strings. Close and timeout state are expressed through dedicated control flow rather than payload sentinels.

Prefer structured task groups for task completion. `Barrier` is intended only for genuine multi-phase coordination.

## Stream readiness and blocking boundary

Runwire-aware stream operations can suspend cooperatively:

```php
$scope->waitReadable($stream);
$scope->waitWritable($stream);
```

These calls use the runtime's `LoopInterface`. They do not make unrelated synchronous functions cooperative. PDO calls, filesystem functions, third-party HTTP clients, and other blocking APIs still block the current worker unless the consumer chooses a non-blocking integration.

## Network migration: callback `Connection` to `AsyncConnection`

`Network\Connection` remains the callback/event-loop core. `Coroutine\Network\AsyncConnection` adapts that core without exposing its raw stream or bypassing buffering/backpressure invariants.

```php
use Infocyph\Runwire\Coroutine\Network\AsyncConnection;

$async = new AsyncConnection($connection, $scope);

$write = $async->write("hello\n");
if ($connection->isWritePressured()) {
    $closeReason = $async->drain();
    if ($closeReason !== null) {
        // The connection closed while waiting for pressure to clear.
    }
}

$data = $async->receive(8192);
$reason = $async->close();
```

The adapter claims exclusive `onData`, `onDrain`, and EOF callback ownership from `Connection`. Constructing an adapter when those callbacks are already owned is a contract conflict and must fail instead of silently replacing application callbacks. `onClose` remains used to resolve pending receive/drain/close waits and preserve the actual `CloseReason`.

When the adapter must stop owning callback slots while the transport intentionally remains open, call `AsyncConnection::dispose()`. Disposal settles adapter-owned waiters, releases the exclusive callback claim, leaves the underlying `Connection` open, and makes that adapter instance unusable.

Only one concurrent `receive()`, `drain()`, or graceful `close()` waiter is supported per `AsyncConnection`. `write()` continues to return the normal `WriteResult`; callers may await `drain()` when backpressure is active.

Migration strategy:

1. leave connection creation, limits, buffering, TLS, close reasons, and writes on `Connection`;
2. replace callback-driven data/drain waiting with one `AsyncConnection` owner inside a coroutine scope;
3. call `dispose()` before transferring callback ownership while keeping the transport open;
4. keep protocol parsing/application semantics above this adapter;
5. do not obtain the underlying stream to bypass `Connection` state.

## Host integration

### Native / SelectLoop

`SelectLoop` is the reference Runwire-owned event loop. Coroutine timers and real stream readiness are driven directly by the Runwire Fiber scheduler.

### Swoole / OpenSwoole

`SwooleLoop` bridges Runwire `LoopInterface` operations to the host reactor and timers. Runwire still uses the same Fiber scheduler and structured semantics; OpenSwoole native coroutines are a host capability/reactor mechanism, not a second Runwire task backend.

The supported bridge is verified against the real OpenSwoole extension on PHP 8.4 and 8.5, including a live HTTP server acceptance lane. It does not run a nested blocking `SelectLoop` inside the host reactor.

### RoadRunner / FrankenPHP / FPM

These hosts can invoke an execution-local `CoroutineRuntime` explicitly. Runwire does not take listener/event-loop ownership away from the host and does not promise transparent asynchronous behavior for arbitrary host APIs.

## Diagnostics

`CoroutineRuntime::diagnostics()` returns a fixed-cardinality `CoroutineDiagnosticsSnapshot`. Worker background scopes expose the same shape through `WorkerContext::backgroundCoroutineDiagnostics()`.

The snapshot includes:

```text
activeTasks
runnableTasks
suspendedTasks
completedTotal
failedTotal
cancelledTotal
spawnedTotal
readyQueueDepth
readyQueueMaxDepth
resumesTotal
rootScopesActive
requestScopesActive
backgroundScopesActive
backgroundTasksActive
loopTimersActive
loopDeferredBacklog
loopReadWatchers
loopWriteWatchers
maxTasks
maxReadyBacklog
maxWaitersPerPrimitive
maxResumesPerTick
```

Counters are scheduler-local and bounded in cardinality. Runwire deliberately does not retain per-task names, labels, completed-task registries, or other unbounded production diagnostic dimensions.

Operationally useful signals include:

- sustained `readyQueueDepth` close to `maxReadyBacklog` — scheduler admission/fairness pressure;
- sustained `activeTasks` close to `maxTasks` — task admission pressure;
- increasing `failedTotal` — application/task failures requiring classification;
- increasing `cancelledTotal` during reload/drain — expected only when lifecycle cancellation is occurring;
- non-zero loop timers/watchers after a completed request/scope — investigate a cleanup leak;
- non-zero `backgroundTasksActive` while a worker should be fully drained — inspect shutdown grace and task cleanup.

## CoroutinePolicy tuning

`CoroutinePolicy` centralizes safety bounds. Current defaults are intentionally finite:

```php
new CoroutinePolicy(
    maxTasks: 1024,
    maxReadyBacklog: 1024,
    maxFutureWaiters: 1024,
    maxResumesPerTick: 128,
    maxWaitersPerPrimitive: 1024,
);
```

Tune only with workload evidence. Raising task or waiter limits increases the amount of work and memory one scheduler can retain during pressure. Raising `maxResumesPerTick` can improve batch throughput but may reduce event-loop fairness. Lower limits fail admission predictably rather than allowing unbounded growth.

Worker shutdown grace is a lifecycle setting on the worker coroutine scope/loop attachment, not a hidden scheduler timeout.

## Hardening and soak acceptance

The release suite includes deterministic race/fault coverage for completion/cancellation, deadline/timer cancellation, real readable/writable stream cancellation, future resolution races, channel close races, sibling failure storms, cleanup exceptions, request isolation, and worker background drain.

Soak coverage repeatedly exercises sequential and bounded-concurrent task churn, channels, cancellation storms, deadline storms, nested groups, persistent request reuse, task-local release, and worker generation replacement. Acceptance requires zero remaining active tasks and zero loop timers/read/write watchers at completed scope boundaries.

## Benchmark evidence

`benchmarks/CoroutineRuntimeBench.php` records PHPBench subjects for:

- task create/start/complete;
- scheduler yield/resume;
- Future await/resolve;
- structured group spawn/join;
- unbuffered channel handoff;
- buffered channel throughput;
- semaphore acquire/release;
- request-root coroutine execution;
- request-context lifecycle without coroutine execution as an adjacent baseline.

These are Runwire-only regression measurements. They are not evidence that Runwire is faster than another coroutine/runtime implementation. Cross-runtime claims still require equivalent real runtimes, hardware, PHP version, protocol, workload, worker count, concurrency, latency, errors, CPU, and RSS as defined in `docs/benchmarks.md`.
