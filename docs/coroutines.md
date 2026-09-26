# Runwire 2.0 Coroutines and Structured Concurrency

Runwire provides structured concurrency built on PHP `Fiber`, `LoopInterface`, and explicit task ownership. It coordinates Runwire-aware timers, I/O readiness, cancellation, request lifecycles, and worker background work without pretending that arbitrary blocking PHP APIs become asynchronous.

## 1. Mental model

One `CoroutineRuntime` owns one scheduler and one event loop.

```text
CoroutineRuntime
└── root CoroutineScope
    ├── Task
    ├── Task
    └── nested CoroutineScope
        └── Task
```

Managed server ownership:

```text
runtime
├── request root scope
│   └── nested groups
│       └── tasks
└── worker background scope
    └── tasks
```

A scope does not silently finish while owned children remain live.

## 2. Basic execution

```php
<?php

declare(strict_types=1);

use Infocyph\Runwire\Coroutine\CoroutineRuntime;
use Infocyph\Runwire\Coroutine\CoroutineScope;

require __DIR__ . '/vendor/autoload.php';

$runtime = new CoroutineRuntime();

$result = $runtime->run(
    static function (CoroutineScope $scope): array {
        $left = $scope->spawn(static function () use ($scope): string {
            $scope->sleep(0.010);
            return 'left';
        });

        $right = $scope->spawn(static function () use ($scope): string {
            $scope->yieldNow();
            return 'right';
        });

        return [$left->await(), $right->await()];
    },
);
```

`CoroutineRuntime::run()` creates the root scope, drives the scheduler/loop, joins structured children, and returns or rethrows the root result.

Nested `run()` on the same runtime is rejected; use the active scope instead.

## 3. Resource policy

Defaults:

```text
maxTasks                1024
maxReadyBacklog         1024
maxFutureWaiters        1024
maxResumesPerTick       128
maxWaitersPerPrimitive  1024
```

Override deliberately:

```php
use Infocyph\Runwire\Coroutine\CoroutinePolicy;

$runtime = new CoroutineRuntime(
    policy: new CoroutinePolicy(
        maxTasks: 2_048,
        maxReadyBacklog: 2_048,
        maxFutureWaiters: 2_048,
        maxResumesPerTick: 256,
        maxWaitersPerPrimitive: 2_048,
    ),
);
```

`maxReadyBacklog` must be at least `maxTasks`. Higher limits retain more scheduler state.

## 4. Tasks

```php
$task = $scope->spawn(static fn(): int => 42);
$value = $task->await();
```

Consumers never resume the underlying Fiber directly. Task result/failure is propagated through scheduler and scope ownership.

## 5. Fail-fast structured groups

```php
$scope->group(
    static function (CoroutineScope $group): void {
        $group->spawn(static function (): void {
            throw new RuntimeException('primary failure');
        });

        $group->spawn(static function () use ($group): void {
            while (true) {
                $group->cancellation()->throwIfCancelled();
                $group->yieldNow();
            }
        });
    },
);
```

Default failure behavior:

1. retain the first unhandled child failure;
2. cancel live siblings;
3. join sibling cleanup;
4. propagate the primary failure.

Collect all failures:

```php
use Infocyph\Runwire\Coroutine\Enum\TaskGroupFailureMode;

$scope->group(
    static function (CoroutineScope $group): void {
        $group->spawn(static fn() => throw new RuntimeException('a'));
        $group->spawn(static fn() => throw new RuntimeException('b'));
    },
    TaskGroupFailureMode::COLLECT_ALL,
);
```

Use collect-all only when observing every child outcome is required.

## 6. Cancellation

```php
$scope->cancellation()->throwIfCancelled();
```

Cancellation is cooperative. Long CPU loops should checkpoint and yield:

```php
for ($i = 0; $i < 1_000_000; ++$i) {
    if (($i % 1_000) === 0) {
        $scope->cancellation()->throwIfCancelled();
        $scope->yieldNow();
    }

    // bounded CPU work
}
```

Cancellation/timer/I/O callbacks enqueue task readiness; they do not recursively resume a Fiber inline.

## 7. Deadlines

Children may tighten but never extend a parent deadline.

```php
use Infocyph\Runwire\RequestDeadline;

$start = hrtime(true);
$start = is_int($start) ? $start : (int) $start;
$deadline = RequestDeadline::afterSeconds(0.250, $start);

$scope->withDeadline(
    $deadline,
    static function (CoroutineScope $limited): void {
        $limited->sleep(0.050);
        $limited->cancellation()->throwIfCancelled();
    },
);
```

The effective child deadline is the earliest applicable monotonic deadline.

## 8. Cooperative sleep and yield

```php
$scope->yieldNow();
$scope->sleep(0.100);
```

These use the Runwire scheduler/loop; they do not call blocking `sleep()`/`usleep()`.

## 9. Stream readiness

For a PHP stream resource:

```php
$scope->waitReadable($stream);
$data = fread($stream, 8192);

$scope->waitWritable($stream);
fwrite($stream, $payload);
```

Only Runwire-aware waiting is cooperative. Arbitrary synchronous APIs remain blocking.

## 10. Channels

Bounded channel:

```php
$channel = $scope->channel(capacity: 2);
```

Producer/consumer:

```php
use Infocyph\Runwire\Coroutine\Exception\ChannelClosedException;

$channel = $scope->channel(2);

$producer = $scope->spawn(static function () use ($channel): void {
    foreach ([1, 2, 3, 4] as $value) {
        $channel->send($value);
    }

    $channel->close();
});

$consumer = $scope->spawn(static function () use ($channel): array {
    $values = [];

    try {
        while (true) {
            $values[] = $channel->receive();
        }
    } catch (ChannelClosedException) {
        return $values;
    }
});

$producer->await();
$values = $consumer->await();
```

Capacity `0` is rendezvous/unbuffered; capacity `>0` is bounded FIFO buffering. `null`, `false`, `0`, and empty strings are valid payloads.

## 11. Future and Deferred

```php
$deferred = $scope->deferred();
$future = $deferred->future();

$scope->spawn(static function () use ($scope, $deferred): void {
    $scope->sleep(0.020);
    $deferred->resolve(['ready' => true]);
});

$value = $future->await();
```

Reject:

```php
$deferred->reject(new RuntimeException('failed'));
```

Completion is one-shot. Future waiters are bounded by policy.

## 12. Semaphore

```php
$semaphore = $scope->semaphore(4);

$tasks = [];
for ($i = 0; $i < 20; ++$i) {
    $tasks[] = $scope->spawn(
        static function () use ($semaphore, $i): int {
            return $semaphore->withPermit(
                static fn(): int => $i * 2,
            );
        },
    );
}

$values = array_map(static fn($task) => $task->await(), $tasks);
```

Manual form:

```php
$semaphore->acquire();
try {
    // guarded work
} finally {
    $semaphore->release();
}
```

## 13. Mutex

```php
$mutex = $scope->mutex();
$counter = 0;

$tasks = [];
for ($i = 0; $i < 10; ++$i) {
    $tasks[] = $scope->spawn(
        static function () use ($mutex, &$counter): void {
            $mutex->synchronized(
                static function () use (&$counter): void {
                    ++$counter;
                },
            );
        },
    );
}

foreach ($tasks as $task) {
    $task->await();
}
```

The mutex is non-recursive and may only be unlocked by its owning task.

## 14. Barrier

```php
$barrier = $scope->barrier(3);

for ($i = 0; $i < 3; ++$i) {
    $scope->spawn(
        static function () use ($barrier, $i): void {
            // phase 1
            $generation = $barrier->wait();

            // phase 2
            printf("task %d passed generation %d\n", $i, $generation);
        },
    );
}
```

Cancellation of a waiting party breaks that generation rather than leaving peers suspended forever.

Use ordinary structured groups for task completion; use barriers for genuine multi-phase coordination.

## 15. Task-local state

```php
use Infocyph\Runwire\Coroutine\TaskLocal;

$traceId = new TaskLocal(default: 'unknown');

$runtime->run(
    static function (CoroutineScope $scope) use ($traceId): void {
        $scope->setLocal($traceId, 'trace-parent');

        $child = $scope->spawn(
            static function () use ($scope, $traceId): string {
                return (string) $scope->local($traceId);
            },
        );

        assert($child->await() === 'trace-parent');
        $scope->removeLocal($traceId);
    },
);
```

Default inheritance is snapshot-based. Task-local state is lifecycle-scoped and does not fall back to mutable process-global storage.

## 16. HTTP request-scoped coroutine handler

```php
<?php

declare(strict_types=1);

use Infocyph\Runwire\Coroutine\CoroutineRuntime;
use Infocyph\Runwire\Coroutine\CoroutineScope;
use Infocyph\Runwire\Http\HttpRequest;
use Infocyph\Runwire\Http\ResponseWriterInterface;
use Infocyph\Runwire\Runtime;
use Infocyph\Runwire\Runtime\CoroutineRequestHandler;
use Infocyph\Runwire\Server;

$coroutines = new CoroutineRuntime();

$handler = new CoroutineRequestHandler(
    $coroutines,
    static function (
        HttpRequest $request,
        ResponseWriterInterface $writer,
        CoroutineScope $scope,
    ): void {
        $profile = $scope->spawn(static function () use ($scope): array {
            $scope->sleep(0.010);
            return ['name' => 'Ada'];
        });

        $settings = $scope->spawn(static function () use ($scope): array {
            $scope->sleep(0.010);
            return ['theme' => 'dark'];
        });

        $writer->end(json_encode([
            'profile' => $profile->await(),
            'settings' => $settings->await(),
        ], JSON_THROW_ON_ERROR));
    },
);

Runtime::create()
    ->listen(Server::http('127.0.0.1:8080', $handler))
    ->run();
```

`CoroutineRuntime::runRequest()` links cancellation/deadline to the request context. A completed request context cannot own new coroutine work.

## 17. Direct request binding

```php
$coroutines->runRequest(
    $request->context,
    static function (CoroutineScope $scope): void {
        // structured request work
    },
);
```

Request completion should leave no request-owned live tasks, timers, stream watchers, cancellation subscriptions, or task-local state.

## 18. Worker background work

Longer-lived work belongs to the worker generation, not a detached request task.

```php
$worker->attachLoop($loop, backgroundShutdownGraceSeconds: 5.0);

$worker->spawnBackground(
    static function (CoroutineScope $scope): void {
        while (true) {
            $scope->cancellation()->throwIfCancelled();

            // process one bounded work unit

            $scope->yieldNow();
        }
    },
);
```

Drain/reload/shutdown stops background admission and cancels/drains generation-owned tasks so old-generation work cannot survive into replacement workers.

Diagnostics:

```php
$worker->backgroundTaskCount();
$worker->backgroundDrainExpired();
$worker->acceptingBackgroundWork();
$worker->backgroundCoroutineDiagnostics();
```

## 19. AsyncConnection

`Network\Connection` remains the callback/event-loop transport core. `AsyncConnection` adapts waiting without exposing the raw stream.

```php
use Infocyph\Runwire\Coroutine\Network\AsyncConnection;

$async = new AsyncConnection($connection, $scope);

$write = $async->write("hello\n");
if ($write->pressured()) {
    $closed = $async->drain();
    if ($closed !== null) {
        // Connection closed while pressure was draining.
    }
}

$data = $async->receive(8192);
$reason = $async->close();
```

Only one concurrent `receive()`, `drain()`, or graceful `close()` wait is supported per adapter.

Release callback ownership without closing the transport:

```php
$async->dispose();
```

Disposal settles adapter waiters, releases exclusive callback ownership, keeps the underlying connection open, and makes the adapter unusable.

## 20. Blocking boundary

These can still block a worker unless the consumer chooses a cooperative/non-blocking integration:

```text
blocking PDO drivers
filesystem calls
blocking curl / HTTP clients
blocking DNS APIs
CPU-heavy loops without yield
arbitrary extension calls
```

Runwire does not install a hook-all monkey patch layer.

## 21. Host integration

### Native / SelectLoop

`SelectLoop` is the built-in Runwire-owned loop. Timers and stream readiness feed the Fiber scheduler.

### Swoole / OpenSwoole

Runwire bridges `LoopInterface` to the host reactor while preserving one Runwire Fiber/task model. Host-native coroutine capability is reported separately.

### FPM / FrankenPHP / RoadRunner

Consumers may explicitly use a bounded `CoroutineRuntime`, but Runwire does not take listener/event-loop ownership from the host or make arbitrary host I/O asynchronous.

## 22. Diagnostics

```php
$snapshot = $runtime->diagnostics();
```

Fixed-cardinality diagnostics include task state/counts, ready-queue depth, resumes, active root/request/background scopes, and loop timer/deferred/read/write watcher counts.

Use diagnostics for operational visibility, not unbounded task history.

## 23. Consumer rules

1. Keep tasks under an owning scope.
2. Do not create request-level fire-and-forget work.
3. Check cancellation in long cooperative loops.
4. Use bounded channels/semaphores instead of unbounded queues.
5. Avoid nested `CoroutineRuntime::run()`.
6. Never directly resume a Runwire-owned Fiber.
7. Understand blocking APIs before placing them in cooperative paths.
8. Dispose `AsyncConnection` before transferring callbacks while keeping the transport open.
9. Keep task-local state small and scoped.
10. Measure scheduler diagnostics before increasing limits.
11. Use `ResponseTransfer::stream()` inside a request `CoroutineScope` when an already-authorized stream must follow response-writer backpressure without monopolizing the loop.

## Related documentation

- [2.0 migration guide](migration-2.0.md)

- [Getting started](getting-started.md)
- [Architecture and runtime contracts](architecture.md)
- [Deployment and operations](deployment.md)
- [Benchmark methodology](benchmarks.md)
- [Runwire 2.0 security/performance tracker](plans/runwire-security-performance-plan.md)
