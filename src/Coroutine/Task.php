<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Coroutine;

use Closure;
use Fiber;
use Infocyph\Runwire\CancellationSource;
use Infocyph\Runwire\CancellationToken;
use Infocyph\Runwire\Coroutine\Enum\TaskState;
use Infocyph\Runwire\Coroutine\Internal\FiberScheduler;
use Infocyph\Runwire\Coroutine\Internal\ReadyItem;
use Infocyph\Runwire\Coroutine\Internal\TaskLocalState;
use Infocyph\Runwire\Exception\CancelledException;
use Infocyph\Runwire\Runtime\Enum\CancellationReason;
use LogicException;
use Throwable;

final class Task
{
    private readonly Deferred $completion;

    /** @var Fiber<mixed, mixed, mixed, mixed> */
    private readonly Fiber $fiber;

    /** @var null|Closure(self): void */
    private readonly ?Closure $onChange;

    private bool $observed = false;

    private TaskState $state = TaskState::NEW;

    private ?Throwable $terminalError = null;

    /** @internal @param null|Closure(self): void $onChange */
    public function __construct(
        private readonly int $id,
        FiberScheduler $scheduler,
        private readonly CancellationSource $cancellationSource,
        callable $callback,
        private readonly TaskLocalState $taskLocals,
        ?Closure $onChange = null,
    ) {
        $closure = $callback(...);
        $token = $cancellationSource->token();
        $this->completion = $scheduler->deferred();
        $this->fiber = new Fiber(static function () use ($closure, $token): mixed {
            $token->throwIfCancelled();

            return $closure();
        });
        $this->onChange = $onChange;
    }

    public function await(): mixed
    {
        $this->markObserved();

        return $this->completion->future()->await();
    }

    /** @internal */
    public function awaitForCleanup(): mixed
    {
        $this->markObserved();

        return $this->completion->future()->awaitForCleanup();
    }

    public function cancel(CancellationReason $reason = CancellationReason::HOST_CANCELLED): bool
    {
        return $this->cancellationSource->cancel($reason);
    }

    public function cancellation(): CancellationToken
    {
        return $this->cancellationSource->token();
    }

    /** @internal */
    public function dispatch(ReadyItem $item): mixed
    {
        $previous = $this->state;
        if ($previous !== TaskState::NEW && $previous !== TaskState::RUNNABLE) {
            throw new LogicException(sprintf('Task %d is not runnable.', $this->id));
        }

        $this->state = TaskState::RUNNING;

        try {
            $signal = $previous === TaskState::NEW
                ? $this->fiber->start()
                : $this->resumeFiber($item);
        } catch (CancelledException $error) {
            $this->finishCancelled($error);

            return null;
        } catch (Throwable $error) {
            $this->finishFailed($error);

            return null;
        }

        if ($this->fiber->isTerminated()) {
            $this->finishCompleted($this->fiber->getReturn());

            return null;
        }
        if (!$this->fiber->isSuspended()) {
            $error = new LogicException(sprintf('Task %d Fiber entered an invalid scheduler state.', $this->id));
            $this->finishFailed($error);

            return null;
        }

        $this->state = TaskState::SUSPENDED;

        return $signal;
    }

    /** @internal */
    public function failure(): ?Throwable
    {
        return $this->terminalError;
    }

    public function id(): int
    {
        return $this->id;
    }

    public function isComplete(): bool
    {
        return match ($this->state) {
            TaskState::CANCELLED, TaskState::COMPLETED, TaskState::FAILED => true,
            default => false,
        };
    }

    /** @internal */
    public function markRunnable(): bool
    {
        if ($this->isComplete() || $this->state === TaskState::RUNNABLE) {
            return false;
        }
        if ($this->state !== TaskState::SUSPENDED) {
            throw new LogicException(sprintf('Task %d cannot become runnable from %s.', $this->id, $this->state->value));
        }

        $this->state = TaskState::RUNNABLE;

        return true;
    }

    /** @internal */
    public function observed(): bool
    {
        return $this->observed;
    }

    public function result(): mixed
    {
        $this->markObserved();

        return $this->completion->future()->result();
    }

    public function state(): TaskState
    {
        return $this->state;
    }

    /** @internal */
    public function taskLocalState(): TaskLocalState
    {
        return $this->taskLocals;
    }

    private function finishCancelled(CancelledException $error): void
    {
        $this->state = TaskState::CANCELLED;
        $this->terminalError = $error;
        $this->cancellationSource->dispose();
        $this->taskLocals->clear();
        $this->completion->reject($error);
        $this->notifyChange();
    }

    private function finishCompleted(mixed $value): void
    {
        $this->state = TaskState::COMPLETED;
        $this->cancellationSource->dispose();
        $this->taskLocals->clear();
        $this->completion->resolve($value);
        $this->notifyChange();
    }

    private function finishFailed(Throwable $error): void
    {
        $this->state = TaskState::FAILED;
        $this->terminalError = $error;
        $this->cancellationSource->dispose();
        $this->taskLocals->clear();
        $this->completion->reject($error);
        $this->notifyChange();
    }

    private function markObserved(): void
    {
        if ($this->observed) {
            return;
        }

        $this->observed = true;
        if ($this->isComplete()) {
            $this->notifyChange();
        }
    }

    private function notifyChange(): void
    {
        if ($this->onChange === null) {
            return;
        }

        try {
            ($this->onChange)($this);
        } catch (Throwable) {
            // Task ownership observers must never corrupt scheduler state.
        }
    }

    private function resumeFiber(ReadyItem $item): mixed
    {
        $token = $this->cancellationSource->token();
        if (!$item->ignoreCancellation && $token->isCancelled()) {
            $reason = $token->reason() ?? CancellationReason::HOST_CANCELLED;

            return $this->fiber->throw(new CancelledException($reason));
        }
        if ($item->error !== null) {
            return $this->fiber->throw($item->error);
        }

        return $this->fiber->resume($item->value);
    }
}
