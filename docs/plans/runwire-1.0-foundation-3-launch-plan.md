# Runwire 1.0 — Coroutine & Structured Concurrency Finalization Plan

## Status

Target release: **Runwire 1.0**

Active branch: `feature/runwire-1.0`

Coroutine implementation baseline before this plan:

```text
894a1bbc696691df3b24efaeccf5dc74085ce045
```

Primary launch consumer: **Foundation 3**  
Primary HTTP integration: **Webrick**  
Primary messaging integration: **Omnibus**  
PHP baseline: **^8.4**

Package identity:

```text
Composer:  infocyph/runwire
Namespace: Infocyph\Runwire
```

Runwire remains the low-level Infocyph process, supervisor, event-loop, network, HTTP and host-runtime substrate.

Priority remains:

> correctness → isolation → structured ownership → bounded resource use → graceful lifecycle → observability → performance → ergonomics

---

# 1. Plan cleanup and scope reset

The previous Runwire 1.0 hardening program, including Batches 0–J and the former 60-point tracker, is complete as implementation history and is **removed from the active development plan**.

Historical certification/evidence remains recorded in:

```text
docs/runwire-1.0-pr-readiness.md
```

Existing behavior and tests from those completed batches remain mandatory regression coverage. They are not re-planned here.

This document now tracks only the remaining coroutine/structured-concurrency work required before the final Runwire 1.0 exact-head release certification.

Because the 1.0 scope is being extended again, no previously green head is the final release head. A new exact-head Benchmarks + Security & Standards certification is required after this plan is complete.

Foundation, Webrick and Omnibus remain untouched until Runwire 1.0 itself is explicitly approved and released.

---

# 2. Architecture decision

Runwire 1.0 will own a **lightweight low-level coroutine and structured-concurrency runtime**.

This reverses the earlier plan boundary that deferred a Fiber scheduler until after 1.0. The new coroutine layer is now part of the Runwire 1.0 release scope.

Runwire will own:

- a PHP `Fiber` based task scheduler;
- deterministic task lifecycle and result/exception propagation;
- structured task groups/scopes;
- cancellation and deadline propagation;
- coroutine-local/task-local context;
- futures/deferred completion;
- bounded channels and synchronization primitives;
- coroutine-aware sleep/yield and event-loop suspension;
- explicit coroutine-aware network/runtime integration;
- lifecycle/drain integration for request, worker and runtime shutdown;
- bounded diagnostics and metrics.

Runwire will **not** own:

- a framework/application container;
- transparent monkey-patching of arbitrary blocking PHP APIs;
- an application-level async ORM/cache/HTTP-client framework;
- Go-style goroutine semantics beyond what PHP Fibers and Runwire's event loop can safely guarantee;
- a dependency on Workerman Coroutine, Revolt, Amp or another coroutine framework;
- Swow support unless Runwire separately adopts Swow as a host runtime;
- unstructured process-global fire-and-forget tasks.

The normative implementation is **Runwire scheduler + PHP Fiber + Runwire `LoopInterface`**. Host-specific engines may provide loop integration, but must not alter public coroutine semantics.

---

# 3. Workerman Coroutine reference — adopt concepts, not implementation

Reference reviewed: `workerman-php/coroutine`.

Useful concepts to carry into Runwire:

- one public coroutine model with backend/runtime adaptation;
- Fiber-backed coroutine execution;
- channels for bounded producer/consumer coordination;
- coroutine-local context;
- barrier/wait coordination;
- parallel execution helpers;
- bounded concurrency/resource coordination.

Runwire should improve on several design choices rather than clone them:

- no process-global static driver selected from a global worker class;
- no direct arbitrary `Fiber::resume()` chains that can create re-entrant scheduler behavior;
- no destructor/garbage-collection driven barrier completion;
- no `false` sentinel for timeout/closed-channel state because `false` must remain a valid payload;
- no coroutine-local state that silently falls back to a mutable process-global non-Fiber context;
- no runtime semantics coupled to Workerman timer/event-loop globals;
- no split semantic behavior between Fiber and Swoole implementations.

Workerman's `Channel`, `Context`, `Barrier`, `Parallel`, `Pool`, `Locker` and `WaitGroup` are useful feature references. Runwire's primary abstraction will instead be **structured task ownership**; `TaskGroup`/`CoroutineScope` should remove most manual WaitGroup/Barrier usage.

---

# 4. Existing Runwire primitives to build on

The coroutine layer must extend, not bypass, the current runtime architecture.

Existing foundations:

```text
src/Loop/LoopInterface.php
src/Loop/SelectLoop.php
src/CancellationToken.php
src/RequestDeadline.php
src/RequestContext.php
src/RuntimeContext.php
src/RuntimeCapabilities.php
src/Runtime/ApplicationLifecycle.php
src/Runtime/RequestExecutionPolicy.php
src/Supervisor/WorkerContext.php
src/Supervisor/Internal/PeriodicTaskRegistry.php
src/Network/Connection.php
```

Important current properties:

- `LoopInterface` already provides defer, delay, repeat, readable/writable watchers, cancellation, monotonic time and run/stop;
- `SelectLoop` is monotonic and already owns timer/watcher/deferred scheduling;
- request deadlines already use monotonic nanoseconds;
- request cancellation is bounded and lifecycle-aware;
- persistent request state is explicitly reset/completed;
- host capabilities already distinguish event-loop ownership and coroutine support;
- network connections are non-blocking and event-loop driven.

Do **not** introduce a second independent timer reactor or polling loop inside the coroutine scheduler.

---

# 5. Core design rules

## 5.1 Scheduler ownership

Each `CoroutineRuntime` owns one scheduler instance. The scheduler is associated with a concrete `LoopInterface` and is never a process-global singleton.

A worker/runtime may own a long-lived scheduler. A non-persistent host may create a bounded execution-local scheduler when coroutine execution is explicitly requested.

A task belongs permanently to exactly one scheduler.

## 5.2 No recursive resume

Callbacks from timers, I/O readiness, cancellation or future completion must **enqueue** a suspended task into the scheduler ready queue. They must not recursively resume a Fiber inline.

The scheduler resumes tasks only from its own dispatch cycle.

This prevents callback-stack re-entry, double resume and scheduler starvation.

## 5.3 Structured ownership by default

Every spawned task must have an owner:

```text
runtime → worker/background scope → request scope → nested task group → task
```

A parent scope may not silently finish while owned child tasks remain live.

Request code does not get an unrestricted `spawnDetached()` escape hatch. Runtime-owned background tasks must use a separate lifecycle-bound background scope that drains/cancels with the worker generation.

## 5.4 Cancellation/deadline inheritance

Child tasks inherit parent cancellation and the earliest applicable monotonic deadline.

Parent cancellation propagates downward.

A child cancelling itself does not automatically cancel its parent. Task-group failure policy decides whether one child failure cancels siblings.

## 5.5 Explicit blocking boundary

Runwire coroutines make Runwire-aware operations cooperative. They do **not** make arbitrary synchronous PHP calls non-blocking.

Blocking filesystem/database/network/client calls continue to block the current worker unless the consumer uses a coroutine-aware/non-blocking integration.

No hidden Swoole-style hook-all behavior belongs in Runwire 1.0.

## 5.6 Bounded everything

Task count, ready backlog, waiters and diagnostic retention must have explicit bounds or be structurally bounded by a parent policy.

No completed-task registry or task-local context may grow indefinitely in a persistent worker.

---

# 6. Proposed public model

Candidate API shape:

```php
$coroutines = new CoroutineRuntime($loop, new CoroutinePolicy());

$result = $coroutines->run(function (CoroutineScope $scope): array {
    $left = $scope->spawn(fn () => loadLeft());
    $right = $scope->spawn(fn () => loadRight());

    return [
        $left->await(),
        $right->await(),
    ];
});
```

The exact names may be refined during implementation, but the semantic model should remain stable.

Primary types:

```text
Coroutine/CoroutineRuntime.php
Coroutine/CoroutinePolicy.php
Coroutine/CoroutineScope.php
Coroutine/Task.php
Coroutine/Future.php
Coroutine/Deferred.php
Coroutine/Channel.php
Coroutine/Mutex.php
Coroutine/Semaphore.php
Coroutine/Barrier.php
Coroutine/TaskLocal.php
Coroutine/Enum/TaskState.php
Coroutine/Enum/TaskGroupFailureMode.php
Coroutine/Exception/*
Coroutine/Internal/FiberScheduler.php
Coroutine/Internal/Suspension.php
Coroutine/Internal/ReadyQueue.php
```

`TaskGroup` may be a dedicated type or the concrete structured implementation behind `CoroutineScope`. Avoid exposing two overlapping concepts unless both have distinct value.

No mandatory global static `Coroutine::create()` API is required for 1.0. Ergonomic helpers can be added only if they resolve an active scheduler without creating mutable process-global runtime state.

---

# 7. Active implementation tracker

Last updated: **2026-09-13**

Tracker rule: mark a batch **✅ Complete** only after the batch implementation and its exact-head Benchmarks + Security & Standards certification are green. Use **🟨 In progress** while implementation or exact-head certification is still active.

| Batch | Scope | Status |
| --- | --- | --- |
| K | Cancellation substrate + coroutine capability normalization | ✅ Complete — certified `2c9b7e4bb823a18bb00c625fbcba2bacbf0b3724` (Benchmarks #78, Security #265) |
| L | Fiber scheduler + Task/Future/Deferred core | ✅ Complete — certified `c7ef116aff09de2645ca918dfab061e182f103ae` (Benchmarks #88, Security #275) |
| M | Structured concurrency + task-local context | ✅ Complete — certified `e7297fd90030b7f7e7d9abd61f94de186e3b48a5` (Benchmarks #93, Security #280) |
| N | Channels + synchronization primitives | ✅ Complete — certified `94643b0c936b088c96744d5d13e41b768deceaae` (Benchmarks #98, Security #285) |
| O | Runtime/request/network/host integration | 🟨 In progress — starting from certified Batch N head `94643b0c936b088c96744d5d13e41b768deceaae` |
| P | Observability + soak/race/interop + benchmarks/docs + exact-head QA | ⬜ Not started |
| Release | Explicit approval, merge/tag/publish | ⬜ Blocked until K–P complete and final exact-head certification is green |

---

# 8. Batch K — cancellation substrate and capability normalization

## K1. Split cancellation authority from observation

The existing cancellation object currently both exposes cancellation state and performs cancellation. Structured concurrency needs clearer ownership.

Introduce a source/token model or equivalent internal authority split:

```text
CancellationSource  → owns cancel()
CancellationToken   → observes state/deadline and subscribes
```

`RequestContext` owns the source internally and continues to expose request-level cancellation behavior through its public lifecycle API.

## K2. Cancellable subscriptions

Cancellation subscriptions used by suspended tasks must be unregisterable after completion/resume.

Required behavior:

- no callback accumulation after repeated waits;
- cancellation/completion race is idempotent;
- one waiter is resumed at most once;
- observer exceptions never corrupt scheduler state.

Add a small subscription handle rather than returning the token itself from registration.

## K3. Cancellation exception/checkpoint

Provide an explicit cancellation checkpoint such as:

```php
$token->throwIfCancelled();
```

Use a dedicated `CancelledException` carrying the cancellation reason.

## K4. Child cancellation scopes

A child task/scope must inherit cancellation/deadline from its parent without consuming an unbounded number of permanent callbacks on the parent token.

Child cancellation propagation should be scheduler/task-tree aware.

## K5. Capability cleanup

The current `supportsCoroutines` host capability must no longer ambiguously mean both host-native coroutine support and Runwire coroutine availability.

Normalize capabilities so diagnostics can distinguish:

```text
Runwire coroutine runtime available
host-native coroutine engine available
host event-loop ownership
Runwire event-loop ownership/bridge availability
```

`RuntimeCapability::CONCURRENT` should describe usable Runwire concurrency semantics, not merely whether the selected host happens to expose Swoole coroutines.

---

# 9. Batch L — Fiber scheduler and task core

## L1. `FiberScheduler`

Implement the normative scheduler on PHP `Fiber`.

Required scheduler behavior:

- FIFO ready queue by default;
- bounded task count/backlog;
- monotonic task IDs scoped to one scheduler;
- explicit task states;
- start/resume/throw only from scheduler dispatch;
- no recursive resume;
- no double resume;
- deterministic terminal cleanup;
- uncaught task exception retained and propagated through task/group ownership;
- scheduler itself survives one task failure;
- configurable max resumes per event-loop tick for fairness.

Suggested states:

```text
NEW
RUNNABLE
RUNNING
SUSPENDED
COMPLETED
FAILED
CANCELLED
```

## L2. `Task`

A `Task` is a scheduler-owned handle with:

- ID/state inspection;
- result retrieval through `await()`;
- exception propagation;
- cancellation request through its owning scope/source;
- completion inspection without forcing result retention forever.

Do not expose raw Fiber mutation to consumers.

## L3. `Future` / `Deferred`

Provide one-shot completion primitives.

Requirements:

- resolve once or reject once;
- multiple awaiters allowed within configured bounds;
- completion before await works;
- cancellation/timeout while awaiting unregisters cleanly;
- result payload may be any PHP value including `false` and `null`;
- producer and consumer sides are separated (`Deferred` vs `Future`).

## L4. Cooperative yield and sleep

Provide scheduler-backed operations for:

```text
yield to ready queue
sleep/delay using LoopInterface::delay()
wait readable
wait writable
```

Timer/watcher handles must always be cancelled/unregistered when a competing completion/cancellation path wins.

## L5. Root `run()` semantics

`CoroutineRuntime::run()` creates the root scope, starts the root task, drives/joins the scheduler according to loop ownership, waits for structured children, then returns the root result or throws its exception.

Nested `run()` on the same scheduler must not start a second event loop.

---

# 10. Batch M — structured concurrency and task-local context

## M1. `CoroutineScope` / task group

The root and nested scopes own children.

Default failure mode should be fail-fast:

1. first unhandled child failure is recorded;
2. siblings are cancelled;
3. the scope waits for sibling cleanup within lifecycle bounds;
4. original failure is rethrown with secondary failures available for diagnostics.

An explicit collect-all mode may wait for all children and return/throw aggregated outcomes.

## M2. Parent completion rule

A scope callback returning does not orphan unfinished children.

The scope must join them according to its policy. If parent cancellation/shutdown occurs, remaining children are cancelled and drained.

## M3. Nested deadlines

A child may request a tighter deadline but never extend its parent's deadline.

Effective deadline:

```text
min(parent deadline, child requested deadline)
```

All scheduler timeout calculations remain monotonic.

## M4. Task-local context

Introduce task-local state without process-global fallback mutation.

Preferred model:

- task locals live on task/scope objects;
- child tasks inherit a snapshot/reference policy explicitly;
- child writes do not accidentally mutate sibling state;
- task-local state is destroyed deterministically at task completion;
- `RequestContext` may be propagated through a dedicated task-local binding rather than copied into arbitrary globals.

Use typed/key-object ownership where practical rather than an unrestricted global string namespace.

## M5. No request leakage

For persistent runtimes, completing one request must leave:

```text
0 request-owned live tasks
0 request-owned timers/watchers
0 request-owned cancellation subscriptions
0 request-owned task-local state
```

This becomes a hard persistent-runtime acceptance condition.

---

# 11. Batch N — channels and synchronization

## N1. `Channel`

Implement bounded FIFO channels.

Required semantics:

- capacity `0` supports rendezvous/unbuffered handoff;
- capacity `>0` supports bounded buffering;
- FIFO producer and consumer waiters;
- `send()` and `receive()` support cancellation/deadline;
- channel close wakes all affected waiters;
- sending to a closed channel has a dedicated closed-state result/exception;
- receiving after close drains buffered values before final closed state;
- `false`, `null`, `0`, empty string and all other PHP values remain legal payloads;
- no boolean sentinel overload for timeout/close.

## N2. `Semaphore`

Provide a bounded permit primitive for concurrency limits.

Requirements:

- FIFO waiters;
- cancellation-safe acquisition;
- no permit leak on exception/cancellation;
- releasing above configured permits is rejected.

## N3. `Mutex`

Build mutex semantics on a dedicated primitive or semaphore core.

Requirements:

- one owner at a time;
- cancellation-safe wait queue;
- release by non-owner rejected;
- no implicit recursive locking unless explicitly designed and tested.

## N4. `Barrier`

If retained, use an explicit counter/generation model. Do **not** rely on object destruction/GC as synchronization semantics.

`TaskGroup` remains the preferred task-completion primitive; Barrier is for genuine phase coordination only.

## N5. Convenience parallelism

After TaskGroup is stable, a small convenience API such as parallel map/run may be added on top of scopes and semaphore limits.

Do not build a second scheduler inside a `Parallel` helper.

## N6. Resource pool decision

Do not add a coroutine resource `Pool` merely because Workerman has one. `Channel` + `Semaphore` already provide the low-level pieces.

Add a first-class pool only if a concrete Runwire-level ownership/lifecycle case cannot be expressed cleanly with those primitives.

Current Batch N decision: **do not add a first-class `Pool` or a second `Parallel` abstraction**. Structured scopes plus `Semaphore` provide bounded parallelism, and `Channel` + `Semaphore` provide the low-level composition needed for resource ownership without duplicating scheduler semantics.

---

# 12. Batch O — runtime, request, network and host integration

## O1. Request root scope

When coroutine execution is enabled for a request, bind one request-owned root scope to the existing `RequestContext` cancellation/deadline.

Request completion order must become:

```text
handler/root task completes
→ structured request children join/cancel
→ coroutine request scope is empty
→ request resetters run
→ RequestContext completes
```

No request-owned task may survive into the next persistent request.

## O2. Worker/background scope

Longer-lived background tasks must be owned by worker/generation lifecycle, not request lifecycle.

Worker drain/reload/shutdown must:

- stop admission of new background work;
- cancel/drain worker-owned tasks;
- enforce a bounded grace period;
- expose remaining task counts in diagnostics;
- never allow tasks from an old generation to continue inside a replacement generation.

## O3. Native/SelectLoop integration

The existing `SelectLoop` is the first reference loop and must pass the full coroutine acceptance suite using real PHP Fibers, real timers and real stream readiness.

## O4. Swoole/OpenSwoole loop bridge

Do not run a blocking nested `SelectLoop` inside a Swoole/OpenSwoole event reactor.

If Runwire claims coroutine support under the Swoole driver, provide a `LoopInterface` bridge/adaptor to the host reactor/timers so the **same Runwire Fiber scheduler** can be driven without blocking the host event loop.

Swoole's native coroutine engine may be detected and reported, but it is not the semantic backend for Runwire tasks in 1.0 unless a later implementation proves full parity without splitting behavior.

Any claimed Swoole/OpenSwoole coroutine integration requires a live extension acceptance lane, not only fake host objects.

## O5. FPM / RoadRunner / FrankenPHP

These runtimes may use an execution-local Runwire coroutine runtime when explicitly invoked.

Rules:

- no promise that arbitrary host I/O becomes async;
- no persistent task survives request completion;
- local scheduler/loop teardown is deterministic;
- host lifecycle ownership remains authoritative.

## O6. Coroutine-aware network adapter

Runwire's current `Network\Connection` is callback/event-loop based. Add coroutine-aware adaptation without replacing the callback core.

Preferred direction:

```text
Coroutine/Network/AsyncConnection
```

or an equivalently isolated adapter that provides awaitable receive/drain/close operations while respecting Connection buffering/backpressure/close reasons.

Do not expose the raw stream merely to bypass Connection invariants.

If the adapter requires exclusive ownership of `onData`/`onDrain` callbacks, make that contract explicit and fail on conflicting ownership rather than silently overriding application callbacks.

## O7. Process/network future extensions

Coroutine-aware process execution, accept loops or higher-level protocol helpers may be added only after the scheduler/network contract is stable. Do not expand scope into unrelated async client frameworks.

---

# 13. Batch P — observability, hardening, benchmarks and docs

## P1. Bounded diagnostics

Expose scheduler snapshots with bounded-cardinality metrics such as:

```text
active tasks
runnable tasks
suspended tasks
completed total
failed total
cancelled total
spawned total
ready queue depth/max
context switches/resumes total
active timers/waiters where available
background/request scope counts
```

Do not expose unbounded per-task labels in production metrics.

## P2. Race/fault acceptance

Explicitly test races including:

- task completion vs cancellation;
- task completion vs timeout;
- cancellation vs timer readiness;
- cancellation vs readable/writable readiness;
- future resolution vs waiter cancellation;
- channel close vs send/receive;
- sibling failure storms;
- request completion with suspended children;
- worker drain with background children;
- repeated nested task groups;
- exception during cleanup/finally.

Every race must prove one terminal transition and no double Fiber resume.

## P3. Soak/leak acceptance

Add long-running acceptance that repeatedly creates/completes/cancels tasks and verifies bounded memory and object/resource cleanup.

Acceptance must cover at least:

```text
large sequential task churn
bounded concurrent task churn
channel producer/consumer churn
cancellation storms
timeout storms
nested scope churn
persistent request-to-request isolation
worker drain/reload with suspended work
```

No scheduler WeakMap/registry/timer/watcher growth may remain after scopes are complete.

## P4. Real I/O acceptance

Use real `stream_socket_pair()`/TCP loopback or equivalent live streams for coroutine readable/writable acceptance.

Synthetic deterministic tests are welcome for race control, but they must not be the only evidence for I/O/coroutine integration.

If Swoole/OpenSwoole integration is claimed, add a real runtime lane with the actual extension.

## P5. Benchmarks

Add PHPBench subjects for:

```text
Fiber task create/start/complete
scheduler yield/resume
Future await/resolve
TaskGroup spawn/join
Channel handoff/buffered throughput
Semaphore acquire/release
request-root coroutine overhead
```

Measure disabled/not-used overhead on normal Runwire request paths as well. Coroutine support must not impose meaningful hot-path cost when unused.

Benchmarks are regression evidence. Do not fabricate Workerman/Swoole/Amp/Revolt comparisons. Cross-runtime claims require equivalent real measurements.

## P6. Documentation

Document:

- coroutine mental model;
- structured task ownership;
- cancellation/deadline propagation;
- explicit blocking boundaries;
- channel/synchronization semantics;
- request vs worker background scopes;
- host capability behavior;
- examples for parallel request work and bounded background work;
- operational diagnostics/tuning;
- migration guidance for callback-style Runwire network code where applicable.

---

# 14. `CoroutinePolicy`

Introduce explicit scheduler safety limits rather than scattered constants.

Candidate shape:

```php
new CoroutinePolicy(
    maxTasks: 4_096,
    maxReadyQueue: 4_096,
    maxWaitersPerPrimitive: 4_096,
    maxResumesPerTick: 512,
    shutdownGraceSeconds: 5.0,
);
```

Exact defaults require benchmark/soak evidence.

Rules:

- limits must be finite and validated;
- exceeding admission bounds fails predictably;
- limits apply per scheduler/scope as documented;
- shutdown grace uses monotonic time;
- diagnostics expose configured/effective limits where useful.

---

# 15. Exception model

Use dedicated coroutine-domain exceptions. Candidate hierarchy:

```text
CoroutineException
├── CancelledException
├── DeadlineExceededException (or cancellation reason on CancelledException)
├── TaskFailedException / aggregate failure representation
├── SchedulerUnavailableException
├── CoroutineLimitExceededException
├── ChannelClosedException
├── SynchronizationException
└── InvalidCoroutineStateException
```

Do not hide original task exceptions behind generic wrappers unless additional context is required. Preserve the original throwable chain.

Cancellation is control flow and must be distinguishable from application failure in metrics and lifecycle handling.

---

# 16. Testing requirements by layer

Unit/contract coverage must include:

```text
CancellationSource/Token/Subscription
Task state machine
FiberScheduler ready/suspend/resume behavior
Future/Deferred
CoroutineScope/TaskGroup
TaskLocal inheritance/isolation
Channel
Semaphore
Mutex
Barrier
CoroutinePolicy validation
capability resolution
```

Integration coverage must include:

```text
SelectLoop timers
SelectLoop readable/writable suspension
RequestContext deadline/cancellation binding
persistent request isolation
worker drain/reload
network adapter/backpressure/close
host-runtime capability behavior
live Swoole/OpenSwoole bridge if claimed
```

No test may make a green result depend on sleeping for arbitrary wall-clock durations when deterministic loop control is possible.

---

# 17. Release gates

Coroutine work is release-blocking once implementation begins.

Runwire 1.0 cannot be tagged until all of the following are true:

- Batches K–P complete;
- previous 0–J regression coverage remains green;
- PHP 8.4 and PHP 8.5 QA green;
- prefer-stable and prefer-lowest lanes green;
- PHPStan/Psalm analyzers green;
- dependency/security audit green;
- clean install green;
- HTTP/1.1/2/3 regression and QUIC interoperability green;
- coroutine real-I/O acceptance green;
- persistent-runtime coroutine isolation green;
- live host acceptance green for every host-specific coroutine capability claimed;
- coroutine soak/race/fault acceptance green;
- PHPBench regression evidence green;
- final documentation reflects actual behavior;
- exact final PR head is frozen and green;
- explicit human approval is given before merge/tag/publish.

Any commit after final certification reopens exact-head certification.

---

# 18. Explicit non-goals for Runwire 1.0 coroutine scope

Do not expand this work into:

- transparent async conversion of PDO/cURL/filesystem functions;
- a userland thread abstraction;
- distributed task execution;
- actor framework;
- application job queue;
- RPC framework;
- application dependency injection/context container;
- arbitrary task migration between worker processes;
- preemptive CPU scheduling.

Fibers are cooperative. CPU-heavy code must yield explicitly or use process-level scaling/offloading.

---

# 19. Implementation order

Proceed strictly in this order:

```text
K  cancellation + capabilities
→ L  scheduler + task/future core
→ M  structured scopes + task locals
→ N  channels/synchronization
→ O  request/worker/network/host integration
→ P  diagnostics + race/soak/real-I/O + benchmarks/docs
→ exact-head release certification
→ explicit approval
→ Runwire 1.0 release
→ Foundation 3 / Webrick / Omnibus integration
```

Do not start Foundation/Webrick/Omnibus coroutine integration from an unreleased Runwire head unless separately authorized.

---

# 20. Definition of success

Runwire's coroutine implementation is successful when it is not merely a Fiber wrapper but a coherent runtime subsystem:

> every task has an owner, every suspension has one wake-up path, every cancellation/deadline propagates predictably, every waiter/timer/watcher is cleaned up, request state cannot leak across persistent requests, host runtimes preserve the same semantics, and unused coroutine support stays cheap.

That is the Runwire 1.0 coroutine bar.