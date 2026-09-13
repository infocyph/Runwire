<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Coroutine\Internal;

use Fiber;
use Infocyph\Runwire\CancellationSource;
use Infocyph\Runwire\CancellationToken;
use Infocyph\Runwire\Coroutine\CoroutineDiagnosticsSnapshot;
use Infocyph\Runwire\Coroutine\CoroutinePolicy;
use Infocyph\Runwire\Coroutine\Deferred;
use Infocyph\Runwire\Coroutine\Enum\TaskState;
use Infocyph\Runwire\Coroutine\Exception\CoroutineDeadlockException;
use Infocyph\Runwire\Coroutine\Exception\CoroutineOverflowException;
use Infocyph\Runwire\Coroutine\Exception\InvalidSuspensionException;
use Infocyph\Runwire\Coroutine\Future;
use Infocyph\Runwire\Coroutine\Task;
use Infocyph\Runwire\Coroutine\TaskLocal;
use Infocyph\Runwire\Loop\LoopDiagnosticsProviderInterface;
use Infocyph\Runwire\Loop\LoopInterface;
use Infocyph\Runwire\Runtime\Enum\CancellationReason;
use LogicException;
use OverflowException;
use Throwable;

/** @internal */
final class FiberScheduler
{
    private readonly SchedulerContext $context;

    private readonly ReadyQueue $ready;

    private int $cancelledTotal = 0;

    private int $completedTotal = 0;

    private ?Task $currentTask = null;

    private bool $draining = false;

    private bool $drainScheduled = false;

    private bool $driving = false;

    private int $failedTotal = 0;

    private int $nextTaskId = 1;

    private int $readyQueueMaxDepth = 0;

    private int $resumesTotal = 0;

    private int $spawnedTotal = 0;

    /** @var array<int, Task> */
    private array $tasks = [];

    public function __construct(LoopInterface $loop, CoroutinePolicy $policy)
    {
        $this->context = new SchedulerContext($loop, $policy);
        $this->ready = new ReadyQueue($policy->maxReadyBacklog);
    }

    /** @internal */
    public function activeTaskCount(): int
    {
        return count($this->tasks);
    }

    public function awaitFuture(
        Future $future,
        bool $cancellable = true,
        bool $ignoreCancellationOnCompletion = false,
    ): mixed {
        $this->requireCurrentTask();

        if ($future->isComplete()) {
            return $future->result();
        }

        return Fiber::suspend(new FutureSuspension(
            $future,
            $cancellable,
            $ignoreCancellationOnCompletion,
        ));
    }

    /** @internal */
    public function currentCancellation(): CancellationToken
    {
        return $this->requireCurrentTask()->cancellation();
    }

    /** @internal */
    public function currentTaskId(): int
    {
        return $this->requireCurrentTask()->id();
    }

    public function deferred(): Deferred
    {
        return new Deferred($this, $this->context->policy->maxFutureWaiters);
    }

    /** @internal */
    public function diagnostics(
        int $rootScopesActive = 0,
        int $requestScopesActive = 0,
        int $backgroundScopesActive = 0,
        int $backgroundTasksActive = 0,
    ): CoroutineDiagnosticsSnapshot {
        $runnableTasks = 0;
        $suspendedTasks = 0;
        foreach ($this->tasks as $task) {
            switch ($task->state()) {
                case TaskState::NEW:
                case TaskState::RUNNABLE:
                case TaskState::RUNNING:
                    ++$runnableTasks;
                    break;
                case TaskState::SUSPENDED:
                    ++$suspendedTasks;
                    break;
                default:
                    break;
            }
        }

        $loopDiagnostics = $this->context->loop instanceof LoopDiagnosticsProviderInterface
            ? $this->context->loop->diagnostics()
            : null;
        $now = hrtime(true);

        return new CoroutineDiagnosticsSnapshot(
            sampledAtMonotonicNanoseconds: is_int($now) ? $now : (int) $now,
            activeTasks: count($this->tasks),
            runnableTasks: $runnableTasks,
            suspendedTasks: $suspendedTasks,
            completedTotal: $this->completedTotal,
            failedTotal: $this->failedTotal,
            cancelledTotal: $this->cancelledTotal,
            spawnedTotal: $this->spawnedTotal,
            readyQueueDepth: $this->ready->count(),
            readyQueueMaxDepth: $this->readyQueueMaxDepth,
            resumesTotal: $this->resumesTotal,
            rootScopesActive: $rootScopesActive,
            requestScopesActive: $requestScopesActive,
            backgroundScopesActive: $backgroundScopesActive,
            backgroundTasksActive: $backgroundTasksActive,
            loopTimersActive: $loopDiagnostics?->timersActive ?? 0,
            loopDeferredBacklog: $loopDiagnostics?->deferredBacklog ?? 0,
            loopReadWatchers: $loopDiagnostics?->readWatchers ?? 0,
            loopWriteWatchers: $loopDiagnostics?->writeWatchers ?? 0,
            maxTasks: $this->context->policy->maxTasks,
            maxReadyBacklog: $this->context->policy->maxReadyBacklog,
            maxWaitersPerPrimitive: $this->context->policy->maxWaitersPerPrimitive,
            maxResumesPerTick: $this->context->policy->maxResumesPerTick,
        );
    }

    public function drive(): void
    {
        if ($this->driving) {
            throw new LogicException('Coroutine scheduler event loop is already being driven.');
        }

        $this->driving = true;

        try {
            if (!$this->ready->isEmpty()) {
                $this->scheduleDrain();
            }
            $this->context->loop->run();
            if ($this->tasks === []) {
                return;
            }

            $liveTasks = count($this->tasks);
            $this->cancelAll(CancellationReason::HOST_CANCELLED);
            if (!$this->ready->isEmpty()) {
                $this->scheduleDrain();
                $this->context->loop->run();
            }

            throw new CoroutineDeadlockException($liveTasks);
        } finally {
            $this->driving = false;
        }
    }

    /** @internal */
    public function hasTaskLocal(TaskLocal $key): bool
    {
        return $this->requireCurrentTask()->taskLocalState()->has($key);
    }

    public function loop(): LoopInterface
    {
        return $this->context->loop;
    }

    /** @internal */
    public function maxWaitersPerPrimitive(): int
    {
        return $this->context->policy->maxWaitersPerPrimitive;
    }

    /** @internal */
    public function removeTaskLocal(TaskLocal $key): bool
    {
        return $this->requireCurrentTask()->taskLocalState()->remove($key);
    }

    public function resume(Task $task, mixed $value = null, bool $ignoreCancellation = false): bool
    {
        return $this->enqueueResume($task, $value, null, $ignoreCancellation);
    }

    public function resumeException(Task $task, Throwable $error, bool $ignoreCancellation = false): bool
    {
        return $this->enqueueResume($task, null, $error, $ignoreCancellation);
    }

    /** @internal */
    public function setTaskLocal(TaskLocal $key, mixed $value): void
    {
        $this->requireCurrentTask()->taskLocalState()->set($key, $value);
    }

    public function sleep(float $seconds): void
    {
        if (!is_finite($seconds) || $seconds < 0.0) {
            throw new \InvalidArgumentException('Coroutine sleep duration must be finite and non-negative.');
        }
        $this->requireCurrentTask();
        if ($seconds === 0.0) {
            $this->yieldNow();

            return;
        }

        Fiber::suspend(new LoopWaitSuspension(
            $this->context->loop,
            fn(\Closure $wake): int => $this->context->loop->delay(
                $seconds,
                static function (int $id) use ($wake): void {
                    unset($id);
                    $wake();
                },
            ),
        ));
    }

    /** @internal */
    public function spawn(
        callable $callback,
        CancellationSource $source,
        ?\Closure $onChange = null,
    ): Task {
        if (count($this->tasks) >= $this->context->policy->maxTasks) {
            throw new CoroutineOverflowException('Coroutine task limit exceeded.');
        }
        if ($this->nextTaskId === PHP_INT_MAX) {
            throw new OverflowException('Coroutine task ID space is exhausted.');
        }

        $taskLocals = $this->currentTask?->taskLocalState()->fork() ?? new TaskLocalState();
        $task = new Task(
            $this->nextTaskId++,
            $this,
            $source,
            $callback,
            $taskLocals,
            $onChange,
        );
        $this->tasks[$task->id()] = $task;
        $this->ready->enqueue($task);
        ++$this->spawnedTotal;
        $this->recordReadyDepth();
        $this->scheduleDrain();

        return $task;
    }

    /** @param resource $stream */
    public function suspendReadable(mixed $stream): void
    {
        $this->requireCurrentTask();
        Fiber::suspend(new LoopWaitSuspension(
            $this->context->loop,
            fn(\Closure $wake): int => $this->context->loop->onReadable(
                $stream,
                static function (mixed $readyStream, int $id) use ($wake): void {
                    unset($readyStream, $id);
                    $wake();
                },
            ),
        ));
    }

    /** @param resource $stream */
    public function suspendWritable(mixed $stream): void
    {
        $this->requireCurrentTask();
        Fiber::suspend(new LoopWaitSuspension(
            $this->context->loop,
            fn(\Closure $wake): int => $this->context->loop->onWritable(
                $stream,
                static function (mixed $readyStream, int $id) use ($wake): void {
                    unset($readyStream, $id);
                    $wake();
                },
            ),
        ));
    }

    /** @internal */
    public function taskLocal(TaskLocal $key): mixed
    {
        return $this->requireCurrentTask()->taskLocalState()->get($key);
    }

    public function yieldNow(): void
    {
        $this->requireCurrentTask();
        Fiber::suspend(new YieldSuspension());
    }

    private function cancelAll(CancellationReason $reason): void
    {
        foreach ($this->tasks as $task) {
            $task->cancel($reason);
        }
    }

    private function dispatch(ReadyItem $item): void
    {
        $task = $item->task;
        $this->currentTask = $task;
        ++$this->resumesTotal;

        try {
            $signal = $task->dispatch($item);
        } finally {
            $this->currentTask = null;
        }

        if ($task->isComplete()) {
            $this->recordTerminal($task);
            unset($this->tasks[$task->id()]);

            return;
        }
        if (!$signal instanceof Suspension) {
            $this->resumeException(
                $task,
                new InvalidSuspensionException('Coroutine task suspended outside Runwire scheduler primitives.'),
            );

            return;
        }

        try {
            $signal->arm($this, $task);
        } catch (Throwable $error) {
            $this->resumeException($task, $error);
        }
    }

    private function drain(): void
    {
        $this->drainScheduled = false;
        if ($this->draining) {
            return;
        }

        $this->draining = true;

        try {
            $resumes = 0;
            while (!$this->ready->isEmpty() && $resumes < $this->context->policy->maxResumesPerTick) {
                $this->dispatch($this->ready->dequeue());
                ++$resumes;
            }
        } finally {
            $this->draining = false;
        }

        if (!$this->ready->isEmpty()) {
            $this->scheduleDrain();
        }
    }

    private function enqueueResume(
        Task $task,
        mixed $value,
        ?Throwable $error,
        bool $ignoreCancellation,
    ): bool {
        if ($task->isComplete()) {
            return false;
        }
        if (!$task->markRunnable()) {
            return false;
        }

        $enqueued = $this->ready->enqueue($task, $value, $error, $ignoreCancellation);
        if ($enqueued) {
            $this->recordReadyDepth();
        }
        if ($enqueued && !$this->draining) {
            $this->scheduleDrain();
        }

        return $enqueued;
    }

    private function recordReadyDepth(): void
    {
        $this->readyQueueMaxDepth = max($this->readyQueueMaxDepth, $this->ready->count());
    }

    private function recordTerminal(Task $task): void
    {
        match ($task->state()) {
            TaskState::CANCELLED => ++$this->cancelledTotal,
            TaskState::COMPLETED => ++$this->completedTotal,
            TaskState::FAILED => ++$this->failedTotal,
            default => null,
        };
    }

    private function requireCurrentTask(): Task
    {
        if ($this->currentTask === null || Fiber::getCurrent() === null) {
            throw new LogicException('Coroutine suspension requires an active Runwire task.');
        }

        return $this->currentTask;
    }

    private function scheduleDrain(): void
    {
        if ($this->drainScheduled) {
            return;
        }

        $this->drainScheduled = true;
        $this->context->loop->defer(function (int $id): void {
            unset($id);
            $this->drain();
        });
    }
}
