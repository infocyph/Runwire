<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Coroutine\Internal;

use Fiber;
use Infocyph\Runwire\CancellationSource;
use Infocyph\Runwire\Coroutine\CoroutinePolicy;
use Infocyph\Runwire\Coroutine\Deferred;
use Infocyph\Runwire\Coroutine\Exception\CoroutineDeadlockException;
use Infocyph\Runwire\Coroutine\Exception\CoroutineOverflowException;
use Infocyph\Runwire\Coroutine\Exception\InvalidSuspensionException;
use Infocyph\Runwire\Coroutine\Future;
use Infocyph\Runwire\Coroutine\Task;
use Infocyph\Runwire\Loop\LoopInterface;
use Infocyph\Runwire\Runtime\Enum\CancellationReason;
use LogicException;
use OverflowException;
use Throwable;

/** @internal */
final class FiberScheduler
{
    private readonly ReadyQueue $ready;

    private ?Task $currentTask = null;

    private bool $drainScheduled = false;

    private bool $draining = false;

    private bool $driving = false;

    private int $nextTaskId = 1;

    /** @var array<int, Task> */
    private array $tasks = [];

    public function __construct(
        private readonly LoopInterface $loop,
        private readonly CoroutinePolicy $policy,
    ) {
        $this->ready = new ReadyQueue($policy->maxReadyBacklog);
    }

    public function awaitFuture(Future $future): mixed
    {
        $this->requireCurrentTask();

        if ($future->isComplete()) {
            return $future->result();
        }

        return Fiber::suspend(new FutureSuspension($future));
    }

    public function deferred(): Deferred
    {
        return new Deferred($this, $this->policy->maxFutureWaiters);
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
            $this->loop->run();
            if ($this->tasks === []) {
                return;
            }

            $liveTasks = count($this->tasks);
            $this->cancelAll(CancellationReason::HOST_CANCELLED);
            if (!$this->ready->isEmpty()) {
                $this->scheduleDrain();
                $this->loop->run();
            }

            throw new CoroutineDeadlockException($liveTasks);
        } finally {
            $this->driving = false;
        }
    }

    public function loop(): LoopInterface
    {
        return $this->loop;
    }

    public function resume(Task $task, mixed $value = null): bool
    {
        return $this->enqueueResume($task, $value, null);
    }

    public function resumeException(Task $task, Throwable $error): bool
    {
        return $this->enqueueResume($task, null, $error);
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
            $this->loop,
            fn(\Closure $wake): int => $this->loop->delay(
                $seconds,
                static function (int $id) use ($wake): void {
                    unset($id);
                    $wake();
                },
            ),
        ));
    }

    /** @internal */
    public function spawn(callable $callback, CancellationSource $source): Task
    {
        if (count($this->tasks) >= $this->policy->maxTasks) {
            throw new CoroutineOverflowException('Coroutine task limit exceeded.');
        }
        if ($this->nextTaskId === PHP_INT_MAX) {
            throw new OverflowException('Coroutine task ID space is exhausted.');
        }

        $task = new Task($this->nextTaskId++, $this, $source, $callback);
        $this->tasks[$task->id()] = $task;
        $this->ready->enqueue($task);
        $this->scheduleDrain();

        return $task;
    }

    public function suspendReadable(mixed $stream): void
    {
        $this->requireCurrentTask();
        Fiber::suspend(new LoopWaitSuspension(
            $this->loop,
            fn(\Closure $wake): int => $this->loop->onReadable(
                $stream,
                static function (mixed $readyStream, int $id) use ($wake): void {
                    unset($readyStream, $id);
                    $wake();
                },
            ),
        ));
    }

    public function suspendWritable(mixed $stream): void
    {
        $this->requireCurrentTask();
        Fiber::suspend(new LoopWaitSuspension(
            $this->loop,
            fn(\Closure $wake): int => $this->loop->onWritable(
                $stream,
                static function (mixed $readyStream, int $id) use ($wake): void {
                    unset($readyStream, $id);
                    $wake();
                },
            ),
        ));
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
        if ($task->isComplete()) {
            unset($this->tasks[$task->id()]);

            return;
        }

        $this->currentTask = $task;

        try {
            $signal = $task->dispatch($item);
        } finally {
            $this->currentTask = null;
        }

        if ($task->isComplete()) {
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
            while (!$this->ready->isEmpty() && $resumes < $this->policy->maxResumesPerTick) {
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

    private function enqueueResume(Task $task, mixed $value, ?Throwable $error): bool
    {
        if ($task->isComplete()) {
            return false;
        }
        if (!$task->markRunnable()) {
            return false;
        }

        $enqueued = $this->ready->enqueue($task, $value, $error);
        if ($enqueued && !$this->draining) {
            $this->scheduleDrain();
        }

        return $enqueued;
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
        $this->loop->defer(function (int $id): void {
            unset($id);
            $this->drain();
        });
    }
}
