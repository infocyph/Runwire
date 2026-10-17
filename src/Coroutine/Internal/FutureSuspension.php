<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Coroutine\Internal;

use Infocyph\Runwire\Coroutine\Future;
use Infocyph\Runwire\Coroutine\Task;
use Throwable;

/** @internal */
final readonly class FutureSuspension implements Suspension
{
    /**
     * Create a suspension waiting on a future.
     */
    public function __construct(
        private Future $future,
        private bool $cancellable = true,
        private bool $ignoreCancellationOnCompletion = false,
    ) {}

    /**
     * Arm the future suspension for the supplied task.
     */
    public function arm(FiberScheduler $scheduler, Task $task): void
    {
        if ($this->future->isComplete()) {
            $this->resumeFromFuture($scheduler, $task);

            return;
        }

        $state = new SuspensionState();
        $finishFuture = function () use ($state, $scheduler, $task): void {
            if (!$this->settle($state)) {
                return;
            }
            $this->resumeFromFuture($scheduler, $task);
        };

        if ($this->cancellable) {
            $cancellation = new CancellationWait(
                $scheduler->loop(),
                $task->cancellation(),
                function (Throwable $error) use ($state, $scheduler, $task): void {
                    if (!$this->settle($state)) {
                        return;
                    }
                    $scheduler->resumeException($task, $error);
                },
            );
            $state->setCancellation($cancellation);
            $cancellation->start();
            if ($state->isSettled()) {
                return;
            }
        }

        $waiterId = $this->future->subscribe($finishFuture);
        $state->setHandle($waiterId);
        if ($waiterId === null) {
            $finishFuture();
        }
    }

    private function resumeFromFuture(FiberScheduler $scheduler, Task $task): void
    {
        $ignoreCancellation = !$this->cancellable || $this->ignoreCancellationOnCompletion;

        try {
            $scheduler->resume($task, $this->future->result(), $ignoreCancellation);
        } catch (Throwable $error) {
            $scheduler->resumeException($task, $error, $ignoreCancellation);
        }
    }

    private function settle(SuspensionState $state): bool
    {
        if (!$state->beginSettlement()) {
            return false;
        }
        $waiterId = $state->takeHandle();
        if ($waiterId !== null) {
            $this->future->unsubscribe($waiterId);
        }
        $state->closeCancellation();

        return true;
    }
}
