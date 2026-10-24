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

    /**
     * Arm the loop-backed suspension for the supplied task.
     */
    public function arm(FiberScheduler $scheduler, Task $task): void
    {
        $state = new SuspensionState();
        $finish = function (?Throwable $error = null) use ($state, $scheduler, $task): void {
            if (!$state->beginSettlement()) {
                return;
            }

            $handle = $state->takeHandle();
            if ($handle !== null) {
                $this->loop->cancel($handle);
            }
            $state->closeCancellation();
            $error === null
                ? $scheduler->resume($task)
                : $scheduler->resumeException($task, $error);
        };

        $cancellation = new CancellationWait(
            $this->loop,
            $task->cancellation(),
            static fn(Throwable $error) => $finish($error),
        );
        $state->setCancellation($cancellation);
        $cancellation->start();
        if ($state->isSettled()) {
            return;
        }

        try {
            $state->setHandle(($this->register)(static fn() => $finish()));
        } catch (Throwable $error) {
            $state->closeCancellation();

            throw $error;
        }
    }
}
