<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Coroutine;

use Closure;
use Infocyph\Runwire\Coroutine\Internal\FiberScheduler;
use Infocyph\Runwire\Coroutine\Internal\FutureState;

final readonly class Future
{
    /** @internal */
    public function __construct(
        private FiberScheduler $scheduler,
        private FutureState $state,
    ) {}

    public function await(): mixed
    {
        if ($this->state->completed()) {
            return $this->state->result();
        }

        return $this->scheduler->awaitFuture($this);
    }

    /** @internal */
    public function awaitForCleanup(): mixed
    {
        if ($this->state->completed()) {
            return $this->state->result();
        }

        return $this->scheduler->awaitFuture($this, false);
    }

    public function isComplete(): bool
    {
        return $this->state->completed();
    }

    public function result(): mixed
    {
        return $this->state->result();
    }

    /** @internal @param Closure(): void $waiter */
    public function subscribe(Closure $waiter): ?int
    {
        return $this->state->subscribe($waiter);
    }

    /** @internal */
    public function unsubscribe(int $id): bool
    {
        return $this->state->unsubscribe($id);
    }
}
