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

/**
 * Stores mutable cancellation state shared by a source and its token.
 *
 * @internal
 */
final class CancellationState
{
    private const int MAX_SUBSCRIPTIONS = 64;

    private bool $cancelled = false;

    private bool $disposed = false;

    private int $nextSubscriptionId = 1;

    private ?CancellationReason $reason = null;

    /** @var array<int, Closure(CancellationToken): void> */
    private array $subscriptions = [];

    /** @var null|Closure(CancellationReason): void */
    private ?Closure $terminalHook = null;

    /** Creates cancellation state with the supplied deadline. */
    public function __construct(private RequestDeadline $deadline) {}

    /** Binds the state to its effective deadline. */
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

    /** Transitions the state to cancelled and notifies active observers once. */
    public function cancel(CancellationReason $reason, CancellationToken $token): bool
    {
        if ($this->cancelled || $this->disposed) {
            return false;
        }

        $this->cancelled = true;
        $this->reason = $reason;
        $subscriptions = $this->subscriptions;
        $this->subscriptions = [];
        $terminalHook = $this->terminalHook;
        $this->terminalHook = null;

        if ($terminalHook !== null) {
            try {
                $terminalHook($reason);
            } catch (Throwable) {
                // Internal cancellation propagation must not corrupt control flow.
            }
        }

        foreach ($subscriptions as $callback) {
            try {
                $callback($token);
            } catch (Throwable) {
                // Cancellation observers are isolated from runtime control flow.
            }
        }

        return true;
    }

    /** Reports whether cancellation has already been requested. */
    public function cancelled(): bool
    {
        return $this->cancelled;
    }

    /** Returns the deadline currently bound to the state. */
    public function deadline(): RequestDeadline
    {
        return $this->deadline;
    }

    /** Releases subscriptions and prevents further cancellation activity. */
    public function dispose(): void
    {
        if ($this->disposed) {
            return;
        }

        $this->subscriptions = [];
        $this->terminalHook = null;
        $this->disposed = true;
    }

    /** Reports whether the state has been disposed. */
    public function disposed(): bool
    {
        return $this->disposed;
    }

    /** Reports whether a subscription handle is still registered. */
    public function hasSubscription(int $id): bool
    {
        return isset($this->subscriptions[$id]);
    }

    /** Returns the cancellation reason when cancellation has occurred. */
    public function reason(): ?CancellationReason
    {
        return $this->reason;
    }

    /**
     * Installs the source-owned terminal propagation hook outside the user observer budget.
     *
     * @internal
     * @param Closure(CancellationReason): void $hook
     */
    public function setTerminalHook(Closure $hook): void
    {
        if ($this->cancelled || $this->disposed || $this->terminalHook !== null) {
            throw new LogicException('Cancellation terminal hook cannot be replaced after state activation.');
        }

        $this->terminalHook = $hook;
    }

    /**
     * Registers a cancellation observer and returns its subscription handle.
     *
     * @param Closure(CancellationToken): void $callback
     */
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

    /** Returns the number of active cancellation observers. */
    public function subscriptionCount(): int
    {
        return count($this->subscriptions);
    }

    /** Removes a cancellation observer by subscription handle. */
    public function unsubscribe(int $id): bool
    {
        if (!isset($this->subscriptions[$id])) {
            return false;
        }

        unset($this->subscriptions[$id]);

        return true;
    }
}
