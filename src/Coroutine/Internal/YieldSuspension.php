<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Coroutine\Internal;

use Infocyph\Runwire\Coroutine\Task;

/** @internal */
final class YieldSuspension implements Suspension
{
    public function arm(FiberScheduler $scheduler, Task $task): void
    {
        $scheduler->resume($task);
    }
}
