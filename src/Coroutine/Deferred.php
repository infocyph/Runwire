<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Coroutine;

use Infocyph\Runwire\Coroutine\Internal\FiberScheduler;
use Infocyph\Runwire\Coroutine\Internal\FutureState;
use Throwable;

/**
 * Completes a future exactly once with either a value or an error.
 */
final readonly class Deferred
{
    private Future $future;

    private FutureState $state;

    /** @internal */
    public function __construct(FiberScheduler $scheduler, int $maxWaiters)
    {
        $this->state = new FutureState($maxWaiters);
        $this->future = new Future($scheduler, $this->state);
    }

    /**
     * Returns the future associated with this deferred result.
     */
    public function future(): Future
    {
        return $this->future;
    }

    /**
     * Completes the future with an error.
     */
    public function reject(Throwable $error): void
    {
        $this->state->reject($error);
    }

    /**
     * Completes the future with a value.
     */
    public function resolve(mixed $value): void
    {
        $this->state->resolve($value);
    }
}
