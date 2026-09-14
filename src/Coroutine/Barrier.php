<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Coroutine;

use Infocyph\Runwire\Coroutine\Exception\BarrierBrokenException;
use Infocyph\Runwire\Coroutine\Exception\SynchronizationException;
use Infocyph\Runwire\Coroutine\Internal\FiberScheduler;
use Infocyph\Runwire\Coroutine\Internal\PrimitiveWaiter;
use Infocyph\Runwire\Exception\CancelledException;
use InvalidArgumentException;

/** Coordinates a fixed number of coroutine parties at reusable synchronization generations. */
final class Barrier
{
    private int $arrived = 0;

    private int $generation = 0;

    private int $nextWaiterId = 1;

    /** @var array<int, PrimitiveWaiter> */
    private array $waiters = [];

    /**
     * Creates a reusable coroutine barrier for the configured number of parties.
     *
     * @internal
     */
    public function __construct(
        private readonly FiberScheduler $scheduler,
        private readonly int $parties,
        int $maxWaiters,
    ) {
        if ($parties < 1) {
            throw new InvalidArgumentException('Coroutine barrier parties must be at least one.');
        }

        if ($parties - 1 > $maxWaiters) {
            throw new InvalidArgumentException('Coroutine barrier parties exceed the configured primitive waiter limit.');
        }
    }

    /** Returns the current barrier generation number. */
    public function generation(): int
    {
        return $this->generation;
    }

    /** Returns the number of parties required to complete a generation. */
    public function parties(): int
    {
        return $this->parties;
    }

    /** Waits for all parties and returns the completed generation number. */
    public function wait(): int
    {
        $cancellation = $this->scheduler->currentCancellation();
        $cancellation->throwIfCancelled();
        $generation = $this->generation;
        if ($this->parties === 1) {
            ++$this->generation;

            return $generation;
        }

        ++$this->arrived;
        if ($this->arrived === $this->parties) {
            if ($this->hasCancelledWaiter()) {
                $this->breakGeneration($generation);

                throw new BarrierBrokenException($generation);
            }

            $this->completeGeneration($generation);

            return $generation;
        }

        $id = $this->nextWaiterId++;
        $waiter = new PrimitiveWaiter($this->scheduler->deferred(), $cancellation);
        $this->waiters[$id] = $waiter;

        try {
            $completedGeneration = $waiter->deferred->future()->awaitCommitted();
            if (!is_int($completedGeneration)) {
                throw new SynchronizationException('Coroutine barrier resumed with an invalid generation result.');
            }

            return $completedGeneration;
        } catch (CancelledException $error) {
            if ($this->generation === $generation && array_key_exists($id, $this->waiters)) {
                unset($this->waiters[$id]);
                $this->breakGeneration($generation);
            }

            throw $error;
        } finally {
            unset($this->waiters[$id]);
        }
    }

    private function breakGeneration(int $generation): void
    {
        if ($this->generation !== $generation) {
            return;
        }

        $waiters = $this->waiters;
        $this->waiters = [];
        $this->arrived = 0;
        ++$this->generation;

        foreach ($waiters as $waiter) {
            if (!$waiter->cancellation->isCancelled()) {
                $waiter->deferred->reject(new BarrierBrokenException($generation));
            }
        }
    }

    private function completeGeneration(int $generation): void
    {
        $waiters = $this->waiters;
        $this->waiters = [];
        $this->arrived = 0;
        ++$this->generation;

        foreach ($waiters as $waiter) {
            $waiter->deferred->resolve($generation);
        }
    }

    private function hasCancelledWaiter(): bool
    {
        return array_any(
            $this->waiters,
            static fn(PrimitiveWaiter $waiter): bool => $waiter->cancellation->isCancelled(),
        );
    }
}
