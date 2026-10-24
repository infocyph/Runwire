<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Coroutine\Internal;

use Closure;
use Infocyph\Runwire\CancellationSubscription;
use Infocyph\Runwire\CancellationToken;
use Infocyph\Runwire\Exception\CancelledException;
use Infocyph\Runwire\Loop\LoopInterface;
use Infocyph\Runwire\Runtime\Enum\CancellationReason;

/**
 * Coordinates cancellation and deadline wake-ups for a suspended coroutine.
 *
 * @internal
 */
final class CancellationWait
{
    /** @var Closure(CancelledException): void */
    private readonly Closure $onCancelled;

    private bool $closed = false;

    private ?int $deadlineTimer = null;

    private ?CancellationSubscription $subscription = null;

    /** @param callable(CancelledException): void $onCancelled */
    public function __construct(
        private readonly LoopInterface $loop,
        private readonly CancellationToken $token,
        callable $onCancelled,
    ) {
        $this->onCancelled = Closure::fromCallable($onCancelled);
    }

    /**
     * Release the cancellation subscription and any pending deadline timer.
     */
    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;
        $this->subscription?->unsubscribe();
        $this->subscription = null;
        if ($this->deadlineTimer !== null) {
            $this->loop->cancel($this->deadlineTimer);
            $this->deadlineTimer = null;
        }
    }

    /**
     * Begin observing cancellation and deadline expiry.
     */
    public function start(): void
    {
        if ($this->closed) {
            return;
        }

        $this->subscription = $this->token->onCancel(function (CancellationToken $token): void {
            if ($this->closed) {
                return;
            }

            $reason = $token->reason() ?? CancellationReason::HOST_CANCELLED;
            $error = new CancelledException($reason);
            $this->close();
            ($this->onCancelled)($error);
        });
        if ($this->closed || $this->token->isCancelled()) {
            return;
        }

        $this->scheduleDeadline();
    }

    private function scheduleDeadline(): void
    {
        $deadline = $this->token->deadline();
        if ($deadline->monotonicNanoseconds === null || $this->closed) {
            return;
        }

        $remaining = $deadline->remainingSeconds();
        if ($remaining <= 0.0) {
            $this->token->isCancelled();

            return;
        }

        $this->deadlineTimer = $this->loop->delay($remaining, function (): void {
            $this->deadlineTimer = null;
            if ($this->closed) {
                return;
            }
            if (!$this->token->isCancelled()) {
                $this->scheduleDeadline();
            }
        });
    }
}
