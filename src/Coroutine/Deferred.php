<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Coroutine;

use Infocyph\Runwire\Coroutine\Internal\FiberScheduler;
use Infocyph\Runwire\Coroutine\Internal\FutureState;
use Throwable;

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

    public function future(): Future
    {
        return $this->future;
    }

    public function reject(Throwable $error): void
    {
        $this->state->reject($error);
    }

    public function resolve(mixed $value): void
    {
        $this->state->resolve($value);
    }
}
