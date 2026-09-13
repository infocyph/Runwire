<?php

declare(strict_types=1);

namespace Infocyph\Runwire;

use Closure;
use Infocyph\Runwire\Runtime\Enum\CancellationReason;
use InvalidArgumentException;
use OverflowException;
use Throwable;

final class CancellationToken
{
    private const int MAX_CALLBACKS = 64;

    /** @var list<Closure(self): void> */
    private array $callbacks = [];

    private bool $cancelled = false;

    private ?CancellationReason $reason = null;

    public function __construct(private RequestDeadline $deadline) {}

    public function cancel(CancellationReason $reason): bool
    {
        if ($this->cancelled) {
            return false;
        }

        $this->cancelled = true;
        $this->reason = $reason;
        $callbacks = $this->callbacks;
        $this->callbacks = [];

        foreach ($callbacks as $callback) {
            self::invoke($callback, $this);
        }

        return true;
    }

    public function clearCallbacks(): void
    {
        $this->callbacks = [];
    }

    public function deadline(): RequestDeadline
    {
        return $this->deadline;
    }

    public function isCancelled(?int $nowNanoseconds = null): bool
    {
        $this->refreshDeadline($nowNanoseconds);

        return $this->cancelled;
    }

    /** @param callable(self): void $callback */
    public function onCancel(callable $callback): self
    {
        $closure = Closure::fromCallable($callback);
        $this->refreshDeadline();
        if ($this->cancelled) {
            self::invoke($closure, $this);

            return $this;
        }
        if (count($this->callbacks) >= self::MAX_CALLBACKS) {
            throw new OverflowException('Cancellation callback limit exceeded.');
        }

        $this->callbacks[] = $closure;

        return $this;
    }

    public function reason(?int $nowNanoseconds = null): ?CancellationReason
    {
        $this->refreshDeadline($nowNanoseconds);

        return $this->reason;
    }

    /** @internal */
    public function setDeadline(RequestDeadline $deadline): void
    {
        if ($this->deadline->monotonicNanoseconds !== null && $deadline->monotonicNanoseconds !== $this->deadline->monotonicNanoseconds) {
            throw new InvalidArgumentException('Cancellation token deadline can only be bound once.');
        }

        $this->deadline = $deadline;
        $this->refreshDeadline();
    }

    private static function invoke(Closure $callback, self $token): void
    {
        try {
            $callback($token);
        } catch (Throwable) {
            // Cancellation observers are isolated from request/runtime control flow.
        }
    }

    private function refreshDeadline(?int $nowNanoseconds = null): void
    {
        if (!$this->cancelled && $this->deadline->expired($nowNanoseconds)) {
            $this->cancel(CancellationReason::DEADLINE_EXCEEDED);
        }
    }
}
