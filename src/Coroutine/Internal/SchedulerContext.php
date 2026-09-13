<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Coroutine\Internal;

use Infocyph\Runwire\Coroutine\CoroutinePolicy;
use Infocyph\Runwire\Loop\LoopInterface;

/** @internal */
final readonly class SchedulerContext
{
    public function __construct(
        public LoopInterface $loop,
        public CoroutinePolicy $policy,
    ) {}
}
