<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Coroutine\Internal;

use Closure;
use Infocyph\Runwire\Coroutine\Exception\CoroutineOverflowException;
use Infocyph\Runwire\Coroutine\Exception\FutureCompletedException;
use LogicException;
use OverflowException;
use Throwable;

/** @internal */
final class FutureState
{
    private bool $completed = false;

    private ?Throwable $error = null;

    private int $nextWaiterId = 1;

    private mixed $value = null;

    /** @var array<int, Closure(): void> */
    private array $waiters = [];

    public function __construct(private readonly int $maxWaiters) {}

    public function completed(): bool
    {
        return $this->completed;
    }

    public function reject(Throwable $error): void
    {
        $this->ensurePending();
        $this->completed = true;
        $this->error = $error;
        $this->notifyWaiters();
    }

    public function resolve(mixed $value): void
    {
        $this->ensurePending();
        $this->completed = true;
        $this->value = $value;
        $this->notifyWaiters();
    }

    public function result(): mixed
    {
        if (!$this->completed) {
            throw new LogicException('Future result is unavailable before completion.');
        }
        if ($this->error !== null) {
            throw $this->error;
        }

        return $this->value;
    }

    /** @param Closure(): void $waiter */
    public function subscribe(Closure $waiter): ?int
    {
        if ($this->completed) {
            return null;
        }
        if (count($this->waiters) >= $this->maxWaiters) {
            throw new CoroutineOverflowException('Future waiter limit exceeded.');
        }
        if ($this->nextWaiterId === PHP_INT_MAX) {
            throw new OverflowException('Future waiter handle space is exhausted.');
        }

        $id = $this->nextWaiterId++;
        $this->waiters[$id] = $waiter;

        return $id;
    }

    public function unsubscribe(int $id): bool
    {
        if (!isset($this->waiters[$id])) {
            return false;
        }

        unset($this->waiters[$id]);

        return true;
    }

    private function ensurePending(): void
    {
        if ($this->completed) {
            throw new FutureCompletedException('Future can only complete once.');
        }
    }

    private function notifyWaiters(): void
    {
        $waiters = $this->waiters;
        $this->waiters = [];

        foreach ($waiters as $waiter) {
            $waiter();
        }
    }
}
