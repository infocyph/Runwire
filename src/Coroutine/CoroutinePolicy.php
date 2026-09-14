<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Coroutine;

use InvalidArgumentException;

/** Defines validated resource and scheduling limits for coroutine execution. */
final readonly class CoroutinePolicy
{
    /** Creates and validates coroutine capacity and per-tick scheduling limits. */
    public function __construct(
        public int $maxTasks = 1_024,
        public int $maxReadyBacklog = 1_024,
        public int $maxFutureWaiters = 1_024,
        public int $maxResumesPerTick = 128,
        public int $maxWaitersPerPrimitive = 1_024,
    ) {
        if ($maxTasks < 1 || $maxTasks > 1_000_000) {
            throw new InvalidArgumentException('Coroutine max task count must be between 1 and 1000000.');
        }
        if ($maxReadyBacklog < $maxTasks || $maxReadyBacklog > 1_000_000) {
            throw new InvalidArgumentException('Coroutine ready backlog must be between maxTasks and 1000000.');
        }
        if ($maxFutureWaiters < 1 || $maxFutureWaiters > 100_000) {
            throw new InvalidArgumentException('Coroutine future waiter limit must be between 1 and 100000.');
        }
        if ($maxResumesPerTick < 1 || $maxResumesPerTick > 10_000) {
            throw new InvalidArgumentException('Coroutine resume budget must be between 1 and 10000 per loop tick.');
        }
        if ($maxWaitersPerPrimitive < 1 || $maxWaitersPerPrimitive > 100_000) {
            throw new InvalidArgumentException('Coroutine primitive waiter limit must be between 1 and 100000.');
        }
    }
}
