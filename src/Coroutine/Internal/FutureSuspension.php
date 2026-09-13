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

        $settled = false;
        $waiterId = null;
        $cancellation = null;
        $finishFuture = function () use (
            &$settled,
            &$waiterId,
            &$cancellation,
            $scheduler,
            $task,
        ): void {
            if ($settled) {
                return;
            }

            $settled = true;
            if ($waiterId !== null) {
                $this->future->unsubscribe($waiterId);
                $waiterId = null;
            }
            $cancellation?->close();
            $this->resumeFromFuture($scheduler, $task);
        };
        $finishCancellation = function (Throwable $error) use (
            &$settled,
            &$waiterId,
            &$cancellation,
            $scheduler,
            $task,
        ): void {
            if ($settled) {
                return;
            }

            $settled = true;
            if ($waiterId !== null) {
                $this->future->unsubscribe($waiterId);
                $waiterId = null;
            }
            $cancellation?->close();
            $scheduler->resumeException($task, $error);
        };

        $cancellation = new CancellationWait(
            $scheduler->loop(),
            $task->cancellation(),
            $finishCancellation,
        );
        $cancellation->start();
        if ($settled) {
            return;
        }

        $waiterId = $this->future->subscribe($finishFuture);
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
