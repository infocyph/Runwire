<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Coroutine;

use Infocyph\Runwire\CancellationSource;
use Infocyph\Runwire\CancellationToken;
use Infocyph\Runwire\Coroutine\Enum\TaskGroupFailureMode;
use Infocyph\Runwire\Coroutine\Enum\TaskState;
use Infocyph\Runwire\Coroutine\Exception\TaskGroupException;
use Infocyph\Runwire\Coroutine\Internal\FiberScheduler;
use Infocyph\Runwire\Exception\CancelledException;
use Infocyph\Runwire\RequestDeadline;
use Infocyph\Runwire\Runtime\Enum\CancellationReason;
use LogicException;
use Throwable;

final class CoroutineScope
{
    /** @var array<int, Task> */
    private array $children = [];

    private bool $closed = false;

    /** @var array<int, Throwable> */
    private array $collectedFailures = [];

    /** @var array<int, int> */
    private array $failureChecks = [];

    private ?Throwable $primaryFailure = null;

    /** @var list<Throwable> */
    private array $secondaryFailures = [];

    /** @internal */
    public function __construct(
        private readonly FiberScheduler $scheduler,
        private readonly CancellationSource $source,
        private readonly TaskGroupFailureMode $failureMode = TaskGroupFailureMode::FAIL_FAST,
    ) {}

    public function barrier(int $parties): Barrier
    {
        $this->assertOpen();

        return new Barrier($this->scheduler, $parties, $this->scheduler->maxWaitersPerPrimitive());
    }

    /** @internal */
    public function cancelChildren(CancellationReason $reason): void
    {
        foreach ($this->children as $task) {
            if (!$task->isComplete()) {
                $task->cancel($reason);
            }
        }
    }

    public function cancellation(): CancellationToken
    {
        return $this->source->token();
    }

    public function channel(int $capacity = 0): Channel
    {
        $this->assertOpen();

        return new Channel($this->scheduler, $capacity, $this->scheduler->maxWaitersPerPrimitive());
    }

    /** @internal */
    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;
        $this->cancelFailureChecks();
        $this->children = [];
        $this->source->dispose();
    }

    public function deferred(): Deferred
    {
        $this->assertOpen();

        return $this->scheduler->deferred();
    }

    /** @internal @param callable(self): mixed $callback */
    public function execute(callable $callback): mixed
    {
        $closure = $callback(...);

        try {
            $result = $closure($this);
            $this->cancellation()->throwIfCancelled();
            $this->join();

            return $result;
        } catch (Throwable $error) {
            $reason = $error instanceof CancelledException
                ? $error->reason
                : CancellationReason::SCOPE_FAILED;
            $this->cancelChildren($reason);
            $this->join(false, true);

            throw $error;
        }
    }

    /** @return list<Throwable> */
    public function failures(): array
    {
        if ($this->failureMode === TaskGroupFailureMode::COLLECT_ALL) {
            $failures = $this->collectedFailures;
            ksort($failures);

            return array_values($failures);
        }
        if ($this->primaryFailure === null) {
            return [];
        }

        return [$this->primaryFailure, ...$this->secondaryFailures];
    }

    /** @param callable(self): mixed $callback */
    public function group(
        callable $callback,
        TaskGroupFailureMode $failureMode = TaskGroupFailureMode::FAIL_FAST,
        ?RequestDeadline $deadline = null,
    ): mixed {
        $this->assertOpen();
        $source = $this->source->child($deadline);
        $group = new self($this->scheduler, $source, $failureMode);
        $closure = $callback(...);

        try {
            $task = $this->spawnOwned(
                static fn(): mixed => $group->execute($closure),
                $source,
            );

            return $task->await();
        } finally {
            $group->close();
        }
    }

    public function hasLocal(TaskLocal $key): bool
    {
        $this->assertOpen();

        return $this->scheduler->hasTaskLocal($key);
    }

    /** @internal */
    public function join(bool $propagateFailure = true, bool $cleanup = false): void
    {
        $tasks = $this->children;

        foreach ($tasks as $task) {
            $this->joinTask($task, $cleanup);
        }

        $this->children = [];
        $this->cancelFailureChecks();

        if (!$propagateFailure) {
            return;
        }

        $failures = $this->failures();
        if ($failures === []) {
            return;
        }
        if ($this->failureMode === TaskGroupFailureMode::COLLECT_ALL) {
            throw new TaskGroupException($failures);
        }

        throw $this->primaryFailure ?? $failures[0];
    }

    public function local(TaskLocal $key): mixed
    {
        $this->assertOpen();

        return $this->scheduler->taskLocal($key);
    }

    public function mutex(): Mutex
    {
        $this->assertOpen();

        return new Mutex($this->scheduler, $this->scheduler->maxWaitersPerPrimitive());
    }

    public function removeLocal(TaskLocal $key): bool
    {
        $this->assertOpen();

        return $this->scheduler->removeTaskLocal($key);
    }

    /** @return list<Throwable> */
    public function secondaryFailures(): array
    {
        return $this->secondaryFailures;
    }

    public function semaphore(int $permits): Semaphore
    {
        $this->assertOpen();

        return new Semaphore($this->scheduler, $permits, $this->scheduler->maxWaitersPerPrimitive());
    }

    public function setLocal(TaskLocal $key, mixed $value): void
    {
        $this->assertOpen();
        $this->scheduler->setTaskLocal($key, $value);
    }

    public function sleep(float $seconds): void
    {
        $this->assertOpen();
        $this->scheduler->sleep($seconds);
    }

    /** @param callable(): mixed $callback */
    public function spawn(callable $callback): Task
    {
        $this->assertOpen();

        return $this->spawnOwned($callback, $this->source->child());
    }

    /** @param resource $stream */
    public function waitReadable(mixed $stream): void
    {
        $this->assertOpen();
        $this->scheduler->suspendReadable($stream);
    }

    /** @param resource $stream */
    public function waitWritable(mixed $stream): void
    {
        $this->assertOpen();
        $this->scheduler->suspendWritable($stream);
    }

    /** @param callable(self): mixed $callback */
    public function withDeadline(
        RequestDeadline $deadline,
        callable $callback,
        TaskGroupFailureMode $failureMode = TaskGroupFailureMode::FAIL_FAST,
    ): mixed {
        return $this->group($callback, $failureMode, $deadline);
    }

    public function yieldNow(): void
    {
        $this->assertOpen();
        $this->scheduler->yieldNow();
    }

    private function assertOpen(): void
    {
        if ($this->closed) {
            throw new LogicException('Coroutine scope is already closed.');
        }
        if ($this->failureMode === TaskGroupFailureMode::FAIL_FAST && $this->primaryFailure !== null) {
            throw $this->primaryFailure;
        }
    }

    private function awaitTask(Task $task, bool $cleanup): void
    {
        if ($cleanup) {
            $task->awaitForCleanup();

            return;
        }

        $task->await();
    }

    private function cancelFailureCheck(int $taskId): void
    {
        $handle = $this->failureChecks[$taskId] ?? null;
        if ($handle === null) {
            return;
        }

        unset($this->failureChecks[$taskId]);
        $this->scheduler->loop()->cancel($handle);
    }

    private function cancelFailureChecks(): void
    {
        $handles = $this->failureChecks;
        $this->failureChecks = [];

        foreach ($handles as $handle) {
            $this->scheduler->loop()->cancel($handle);
        }
    }

    private function cancelSiblings(int $failedTaskId): void
    {
        foreach ($this->children as $taskId => $task) {
            if ($taskId !== $failedTaskId && !$task->isComplete()) {
                $task->cancel(CancellationReason::SCOPE_FAILED);
            }
        }
    }

    private function handleDeferredFailure(Task $task): void
    {
        unset($this->failureChecks[$task->id()]);
        if ($this->closed || $task->observed()) {
            return;
        }
        if ($task->state() !== TaskState::FAILED) {
            return;
        }

        $error = $task->failure();
        if ($error === null) {
            return;
        }

        $this->recordFailure($error);
        $this->cancelSiblings($task->id());
        unset($this->children[$task->id()]);
    }

    private function handleJoinedCancellation(CancelledException $error, bool $cleanup): void
    {
        if (!$cleanup && $this->cancellation()->isCancelled()) {
            throw $error;
        }
    }

    private function handleJoinedFailure(Task $task, Throwable $error, bool $cleanup): void
    {
        if ($task->state() !== TaskState::FAILED) {
            if (!$cleanup) {
                throw $error;
            }

            return;
        }
        if ($this->failureMode === TaskGroupFailureMode::COLLECT_ALL) {
            $this->recordCollectAllFailure($task->id(), $error);

            return;
        }

        $this->recordFailure($error);
        $this->cancelSiblings($task->id());
    }

    private function joinTask(Task $task, bool $cleanup): void
    {
        if ($task->isComplete() && $task->observed()) {
            unset($this->children[$task->id()]);

            return;
        }

        try {
            $this->awaitTask($task, $cleanup);
        } catch (CancelledException $error) {
            $this->handleJoinedCancellation($error, $cleanup);
        } catch (Throwable $error) {
            $this->handleJoinedFailure($task, $error, $cleanup);
        }

        unset($this->children[$task->id()]);
    }

    private function onTaskChange(Task $task): void
    {
        if (!$task->isComplete()) {
            return;
        }
        if ($task->observed()) {
            $this->cancelFailureCheck($task->id());
            unset($this->children[$task->id()]);

            return;
        }
        if ($task->state() !== TaskState::FAILED) {
            unset($this->children[$task->id()]);

            return;
        }

        $error = $task->failure();
        if ($error === null || $this->closed) {
            return;
        }
        if ($this->failureMode === TaskGroupFailureMode::COLLECT_ALL) {
            $this->recordCollectAllFailure($task->id(), $error);
            unset($this->children[$task->id()]);

            return;
        }
        if (isset($this->failureChecks[$task->id()])) {
            return;
        }

        $this->failureChecks[$task->id()] = $this->scheduler->loop()->defer(
            function (int $id) use ($task): void {
                unset($id);
                $this->handleDeferredFailure($task);
            },
        );
    }

    private function recordCollectAllFailure(int $taskId, Throwable $error): void
    {
        $this->collectedFailures[$taskId] = $error;
    }

    private function recordFailure(Throwable $error): void
    {
        if ($this->primaryFailure === null) {
            $this->primaryFailure = $error;

            return;
        }
        if ($this->primaryFailure === $error) {
            return;
        }

        foreach ($this->secondaryFailures as $secondary) {
            if ($secondary === $error) {
                return;
            }
        }

        $this->secondaryFailures[] = $error;
    }

    /** @param callable(): mixed $callback */
    private function spawnOwned(callable $callback, CancellationSource $source): Task
    {
        $task = $this->scheduler->spawn(
            $callback,
            $source,
            function (Task $task): void {
                $this->onTaskChange($task);
            },
        );
        $this->children[$task->id()] = $task;

        return $task;
    }
}
