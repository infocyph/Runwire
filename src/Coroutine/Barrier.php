<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Coroutine;

use Infocyph\Runwire\Coroutine\Exception\BarrierBrokenException;
use Infocyph\Runwire\Coroutine\Internal\FiberScheduler;
use Infocyph\Runwire\Coroutine\Internal\PrimitiveWaiter;
use Infocyph\Runwire\Exception\CancelledException;
use InvalidArgumentException;

final class Barrier
{
    private int $arrived = 0;

    private int $generation = 0;

    private int $nextWaiterId = 1;

    /** @var array<int, PrimitiveWaiter> */
    private array $waiters = [];

    /** @internal */
    public function __construct(
        private readonly FiberScheduler $scheduler,
        private readonly int $parties,
        private readonly int $maxWaiters,
    ) {
        if ($parties < 1) {
            throw new InvalidArgumentException('Coroutine barrier parties must be at least one.');
        }
        if ($parties - 1 > $maxWaiters) {
            throw new InvalidArgumentException('Coroutine barrier parties exceed the configured primitive waiter limit.');
        }
    }

    public function generation(): int
    {
        return $this->generation;
    }

    public function parties(): int
    {
        return $this->parties;
    }

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
            return $waiter->deferred->future()->awaitCommitted();
        } catch (CancelledException $error) {
            if ($this->generation === $generation && isset($this->waiters[$id])) {
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
        foreach ($this->waiters as $waiter) {
            if ($waiter->cancellation->isCancelled()) {
                return true;
            }
        }

        return false;
    }
}
