<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Coroutine\Internal;

use Infocyph\Runwire\Coroutine\Task;

/** @internal */
interface Suspension
{
    /**
     * Arm the suspension for the supplied scheduler task.
     */
    public function arm(FiberScheduler $scheduler, Task $task): void;
}
