<?php

declare(strict_types=1);

namespace Infocyph\Runwire;

use Closure;
use Infocyph\Runwire\Cancellation\Internal\CancellationState;
use Infocyph\Runwire\Exception\CancelledException;
use Infocyph\Runwire\Runtime\Enum\CancellationReason;
use Throwable;

final class CancellationToken
{
    /** @internal Cancellation tokens are created by CancellationSource. */
    public function __construct(private readonly CancellationState $state) {}

    public function deadline(): RequestDeadline
    {
        return $this->state->deadline();
    }

    public function isCancelled(?int $nowNanoseconds = null): bool
    {
        $this->refreshDeadline($nowNanoseconds);

        return $this->state->cancelled();
    }

    /** @param callable(self): void $callback */
    public function onCancel(callable $callback): CancellationSubscription
    {
        $closure = Closure::fromCallable($callback);
        $this->refreshDeadline();

        if ($this->state->cancelled()) {
            self::invoke($closure, $this);

            return new CancellationSubscription($this->state, null);
        }
        if ($this->state->disposed()) {
            return new CancellationSubscription($this->state, null);
        }

        return new CancellationSubscription(
            $this->state,
            $this->state->subscribe($closure),
        );
    }

    public function reason(?int $nowNanoseconds = null): ?CancellationReason
    {
        $this->refreshDeadline($nowNanoseconds);

        return $this->state->reason();
    }

    public function throwIfCancelled(?int $nowNanoseconds = null): void
    {
        $this->refreshDeadline($nowNanoseconds);
        $reason = $this->state->reason();
        if ($reason !== null) {
            throw new CancelledException($reason);
        }
    }

    private static function invoke(Closure $callback, self $token): void
    {
        try {
            $callback($token);
        } catch (Throwable) {
            // Cancellation observers are isolated from runtime control flow.
        }
    }

    private function refreshDeadline(?int $nowNanoseconds = null): void
    {
        if (
            !$this->state->cancelled()
            && !$this->state->disposed()
            && $this->state->deadline()->expired($nowNanoseconds)
        ) {
            $this->state->cancel(CancellationReason::DEADLINE_EXCEEDED, $this);
        }
    }
}
