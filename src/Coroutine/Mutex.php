<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Coroutine;

use Infocyph\Runwire\Coroutine\Exception\CoroutineOverflowException;
use Infocyph\Runwire\Coroutine\Exception\SynchronizationException;
use Infocyph\Runwire\Coroutine\Internal\FiberScheduler;
use Infocyph\Runwire\Coroutine\Internal\PrimitiveWaiter;

final class Mutex
{
    private int $nextWaiterId = 1;

    private ?int $ownerTaskId = null;

    /** @var array<int, PrimitiveWaiter> */
    private array $waiters = [];

    /** @internal */
    public function __construct(
        private readonly FiberScheduler $scheduler,
        private readonly int $maxWaiters,
    ) {}

    public function isLocked(): bool
    {
        return $this->ownerTaskId !== null;
    }

    public function lock(): void
    {
        $taskId = $this->scheduler->currentTaskId();
        $cancellation = $this->scheduler->currentCancellation();
        $cancellation->throwIfCancelled();
        if ($this->ownerTaskId === null) {
            $this->ownerTaskId = $taskId;

            return;
        }
        if ($this->ownerTaskId === $taskId) {
            throw new SynchronizationException('Coroutine mutex does not support recursive locking.');
        }
        if (count($this->waiters) >= $this->maxWaiters) {
            throw new CoroutineOverflowException('Coroutine mutex waiter limit exceeded.');
        }

        $id = $this->nextWaiterId++;
        $waiter = new PrimitiveWaiter($this->scheduler->deferred(), $cancellation, $taskId);
        $this->waiters[$id] = $waiter;

        try {
            $waiter->deferred->future()->awaitCommitted();
        } finally {
            unset($this->waiters[$id]);
        }
    }

    public function ownerTaskId(): ?int
    {
        return $this->ownerTaskId;
    }

    /** @param callable(): mixed $callback */
    public function synchronized(callable $callback): mixed
    {
        $this->lock();

        try {
            return $callback();
        } finally {
            $this->unlock();
        }
    }

    public function unlock(): void
    {
        $taskId = $this->scheduler->currentTaskId();
        if ($this->ownerTaskId !== $taskId) {
            throw new SynchronizationException('Coroutine mutex can only be released by its owning task.');
        }

        $waiter = $this->takeWaiter();
        if ($waiter === null) {
            $this->ownerTaskId = null;

            return;
        }

        $this->ownerTaskId = $waiter->ownerTaskId;
        $waiter->deferred->resolve(null);
    }

    private function takeWaiter(): ?PrimitiveWaiter
    {
        while ($this->waiters !== []) {
            $id = array_key_first($this->waiters);
            $waiter = $this->waiters[$id];
            unset($this->waiters[$id]);
            if (!$waiter->cancellation->isCancelled()) {
                return $waiter;
            }
        }

        return null;
    }
}
