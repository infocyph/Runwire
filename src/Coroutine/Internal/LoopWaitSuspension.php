<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Coroutine\Internal;

use Closure;
use Infocyph\Runwire\Coroutine\Task;
use Infocyph\Runwire\Loop\LoopInterface;
use Throwable;

/** @internal */
final readonly class LoopWaitSuspension implements Suspension
{
    /** @var Closure(Closure(): void): int */
    private Closure $register;

    /** @param callable(Closure(): void): int $register */
    public function __construct(
        private LoopInterface $loop,
        callable $register,
    ) {
        $this->register = $register(...);
    }

    public function arm(FiberScheduler $scheduler, Task $task): void
    {
        $settled = false;
        $handle = null;
        $cancellation = null;

        $finish = function (?Throwable $error = null) use (
            &$settled,
            &$handle,
            &$cancellation,
            $scheduler,
            $task,
        ): void {
            if ($settled) {
                return;
            }

            $settled = true;
            if ($handle !== null) {
                $this->loop->cancel($handle);
                $handle = null;
            }
            $cancellation?->close();
            $error === null
                ? $scheduler->resume($task)
                : $scheduler->resumeException($task, $error);
        };

        $cancellation = new CancellationWait(
            $this->loop,
            $task->cancellation(),
            static fn(Throwable $error) => $finish($error),
        );
        $cancellation->start();
        if ($settled) {
            return;
        }

        try {
            $handle = ($this->register)(static fn() => $finish());
        } catch (Throwable $error) {
            $cancellation->close();

            throw $error;
        }
    }
}
