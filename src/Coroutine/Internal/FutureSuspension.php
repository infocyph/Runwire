<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Coroutine\Internal;

use Infocyph\Runwire\Coroutine\Future;
use Infocyph\Runwire\Coroutine\Task;
use Throwable;

/** @internal */
final readonly class FutureSuspension implements Suspension
{
    public function __construct(private Future $future) {}

    public function arm(FiberScheduler $scheduler, Task $task): void
    {
        if ($this->future->isComplete()) {
            $this->resumeFromFuture($scheduler, $task);

            return;
        }

        $state = new SuspensionState();
        $finishFuture = function () use ($state, $scheduler, $task): void {
            if (!$state->beginSettlement()) {
                return;
            }

            $waiterId = $state->takeHandle();
            if ($waiterId !== null) {
                $this->future->unsubscribe($waiterId);
            }
            $state->closeCancellation();
            $this->resumeFromFuture($scheduler, $task);
        };
        $finishCancellation = function (Throwable $error) use ($state, $scheduler, $task): void {
            if (!$state->beginSettlement()) {
                return;
            }

            $waiterId = $state->takeHandle();
            if ($waiterId !== null) {
                $this->future->unsubscribe($waiterId);
            }
            $state->closeCancellation();
            $scheduler->resumeException($task, $error);
        };

        $cancellation = new CancellationWait(
            $scheduler->loop(),
            $task->cancellation(),
            $finishCancellation,
        );
        $state->setCancellation($cancellation);
        $cancellation->start();
        if ($state->isSettled()) {
            return;
        }

        $waiterId = $this->future->subscribe($finishFuture);
        $state->setHandle($waiterId);
        if ($waiterId === null) {
            $finishFuture();
        }
    }

    private function resumeFromFuture(FiberScheduler $scheduler, Task $task): void
    {
        try {
            $scheduler->resume($task, $this->future->result());
        } catch (Throwable $error) {
            $scheduler->resumeException($task, $error);
        }
    }
}
