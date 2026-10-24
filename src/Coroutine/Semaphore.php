<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Coroutine;

use Infocyph\Runwire\Coroutine\Exception\CoroutineOverflowException;
use Infocyph\Runwire\Coroutine\Exception\SynchronizationException;
use Infocyph\Runwire\Coroutine\Internal\FiberScheduler;
use Infocyph\Runwire\Coroutine\Internal\PrimitiveWaiter;
use InvalidArgumentException;

/**
 * Limits concurrent coroutine access through a fixed permit count.
 */
final class Semaphore
{
    private int $available;

    private int $nextWaiterId = 1;

    /** @var array<int, PrimitiveWaiter> */
    private array $waiters = [];

    /** @internal */
    public function __construct(
        private readonly FiberScheduler $scheduler,
        private readonly int $permits,
        private readonly int $maxWaiters,
    ) {
        if ($permits < 1) {
            throw new InvalidArgumentException('Coroutine semaphore permits must be at least one.');
        }

        $this->available = $permits;
    }

    /**
     * Acquire one permit for the current task.
     */
    public function acquire(): void
    {
        $cancellation = $this->scheduler->currentCancellation();
        $cancellation->throwIfCancelled();
        if ($this->available > 0) {
            --$this->available;

            return;
        }
        if (count($this->waiters) >= $this->maxWaiters) {
            throw new CoroutineOverflowException('Coroutine semaphore waiter limit exceeded.');
        }

        $id = $this->nextWaiterId++;
        $waiter = new PrimitiveWaiter($this->scheduler->deferred(), $cancellation);
        $this->waiters[$id] = $waiter;

        try {
            $waiter->deferred->future()->awaitCommitted();
        } finally {
            unset($this->waiters[$id]);
        }
    }

    /**
     * Return the number of currently available permits.
     */
    public function availablePermits(): int
    {
        return $this->available;
    }

    /**
     * Return the configured maximum number of permits.
     */
    public function maxPermits(): int
    {
        return $this->permits;
    }

    /**
     * Release one permit or wake the next waiting task.
     */
    public function release(): void
    {
        $waiter = $this->takeWaiter();
        if ($waiter !== null) {
            $waiter->deferred->resolve(null);

            return;
        }
        if ($this->available >= $this->permits) {
            throw new SynchronizationException('Coroutine semaphore cannot be released above its configured permits.');
        }

        ++$this->available;
    }

    /** @param callable(): mixed $callback */
    public function withPermit(callable $callback): mixed
    {
        $this->acquire();

        try {
            return $callback();
        } finally {
            $this->release();
        }
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
