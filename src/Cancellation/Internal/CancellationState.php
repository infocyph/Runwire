<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Cancellation\Internal;

use Closure;
use Infocyph\Runwire\CancellationToken;
use Infocyph\Runwire\RequestDeadline;
use Infocyph\Runwire\Runtime\Enum\CancellationReason;
use InvalidArgumentException;
use LogicException;
use OverflowException;
use Throwable;

/** @internal */
final class CancellationState
{
    private const int MAX_SUBSCRIPTIONS = 64;

    private bool $cancelled = false;

    private bool $disposed = false;

    private int $nextSubscriptionId = 1;

    private ?CancellationReason $reason = null;

    /** @var array<int, Closure(CancellationToken): void> */
    private array $subscriptions = [];

    public function __construct(private RequestDeadline $deadline) {}

    public function bindDeadline(RequestDeadline $deadline): void
    {
        if ($this->disposed) {
            throw new LogicException('Disposed cancellation state cannot be rebound.');
        }
        if (
            $this->deadline->monotonicNanoseconds !== null
            && $deadline->monotonicNanoseconds !== $this->deadline->monotonicNanoseconds
        ) {
            throw new InvalidArgumentException('Cancellation deadline can only be bound once.');
        }

        $this->deadline = $deadline;
    }

    public function cancel(CancellationReason $reason, CancellationToken $token): bool
    {
        if ($this->cancelled || $this->disposed) {
            return false;
        }

        $this->cancelled = true;
        $this->reason = $reason;
        $subscriptions = $this->subscriptions;
        $this->subscriptions = [];

        foreach ($subscriptions as $callback) {
            try {
                $callback($token);
            } catch (Throwable) {
                // Cancellation observers are isolated from runtime control flow.
            }
        }

        return true;
    }

    public function cancelled(): bool
    {
        return $this->cancelled;
    }

    public function deadline(): RequestDeadline
    {
        return $this->deadline;
    }

    public function dispose(): void
    {
        if ($this->disposed) {
            return;
        }

        $this->subscriptions = [];
        $this->disposed = true;
    }

    public function disposed(): bool
    {
        return $this->disposed;
    }

    public function hasSubscription(int $id): bool
    {
        return isset($this->subscriptions[$id]);
    }

    public function reason(): ?CancellationReason
    {
        return $this->reason;
    }

    /** @param Closure(CancellationToken): void $callback */
    public function subscribe(Closure $callback): int
    {
        if ($this->cancelled || $this->disposed) {
            throw new LogicException('Cannot subscribe to terminal cancellation state.');
        }
        if (count($this->subscriptions) >= self::MAX_SUBSCRIPTIONS) {
            throw new OverflowException('Cancellation subscription limit exceeded.');
        }
        if ($this->nextSubscriptionId === PHP_INT_MAX) {
            throw new OverflowException('Cancellation subscription handle space is exhausted.');
        }

        $id = $this->nextSubscriptionId++;
        $this->subscriptions[$id] = $callback;

        return $id;
    }

    public function subscriptionCount(): int
    {
        return count($this->subscriptions);
    }

    public function unsubscribe(int $id): bool
    {
        if (!isset($this->subscriptions[$id])) {
            return false;
        }

        unset($this->subscriptions[$id]);

        return true;
    }
}
