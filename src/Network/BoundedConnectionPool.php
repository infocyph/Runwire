<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Network;

use Closure;
use Infocyph\Runwire\Loop\LoopInterface;
use InvalidArgumentException;
use OverflowException;
use RuntimeException;
use Throwable;

/**
 * Bounded active leases and FIFO waiters around verified idle connections.
 *
 * A callback with a null socket and null error grants one bounded slot to
 * establish an independent verified connection. The caller MUST release the
 * returned lease even if dialing fails. This class never dials or downgrades.
 */
final class BoundedConnectionPool
{
    private readonly IdleConnectionPool $idle;

    /** @var array<int, PoolIsolationKey> */
    private array $active = [];

    private bool $closed = false;

    private int $nextId = 1;

    /** @var array<int, array{key:PoolIsolationKey, callback:Closure, timer:int}> */
    private array $waiting = [];

    /** Bound simultaneous active ownership, waiters and idle retention. */
    public function __construct(
        private readonly LoopInterface $loop,
        private readonly int $maxActive = 16,
        private readonly int $maxWaiting = 128,
        int $maxIdle = 16,
        float $idleTtlSeconds = 30.0,
    ) {
        if ($maxActive < 1 || $maxActive > 65_536 || $maxWaiting < 1
            || $maxWaiting > 65_536 || $maxIdle < 1 || $maxIdle > $maxActive) {
            throw new InvalidArgumentException('Connection pool lease and waiter limits are invalid.');
        }

        $this->idle = new IdleConnectionPool($loop, $maxIdle, $idleTtlSeconds);
    }

    /**
     * Request an existing healthy socket or a fresh dial slot; no busy retry.
     * @param callable(int,?Connection,?Throwable):void $ready
     */
    public function acquire(PoolIsolationKey $key, callable $ready, float $waitSeconds = 10.0): int
    {
        if ($this->closed || !is_finite($waitSeconds) || $waitSeconds <= 0
            || $this->nextId === PHP_INT_MAX) {
            throw new InvalidArgumentException('Pool is closed or waiter deadline/identity is invalid.');
        }
        $id = $this->nextId++;
        $callback = Closure::fromCallable($ready);
        if (count($this->active) < $this->maxActive && $this->waiting === []) {
            $this->grant($id, $key, $callback);

            return $id;
        }
        if (count($this->waiting) >= $this->maxWaiting) {
            throw new OverflowException('Connection pool waiter quota exceeded.');
        }

        $timer = $this->loop->delay($waitSeconds, function () use ($id): void {
            $this->rejectWaiter($id, new RuntimeException('Connection pool waiter timed out.'));
        });
        $this->waiting[$id] = ['key' => $key, 'callback' => $callback, 'timer' => $timer];

        return $id;
    }

    /** Number of leased (including pending dial) active connection slots. */
    public function activeCount(): int
    {
        return count($this->active);
    }

    /** Cancel a queued waiter exactly once; active leases use release(). */
    public function cancel(int $id): bool
    {
        if (!isset($this->waiting[$id])) {
            return false;
        }
        $this->rejectWaiter($id, new RuntimeException('Connection pool waiter was canceled.'));

        return true;
    }

    /** Shut down all idle connections and settle queued waiters. Active leases are caller-owned. */
    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        $waiters = $this->waiting;
        $this->waiting = [];
        $firstError = null;
        foreach ($waiters as $id => $entry) {
            $this->loop->cancel($entry['timer']);

            try {
                ($entry['callback'])($id, null, new RuntimeException('Connection pool closed.'));
            } catch (Throwable $error) {
                $firstError ??= $error;
            }
        }
        $this->idle->close();
        if ($firstError !== null) {
            throw $firstError;
        }
    }

    /** Number of quiescent, verified sockets available for identity-safe reuse. */
    public function idleCount(): int
    {
        return $this->idle->count();
    }

    /** Retire a lease; unhealthy, foreign or closed-pool sockets are aborted. */
    public function release(int $id, ?Connection $connection = null): bool
    {
        $key = $this->active[$id] ?? null;
        if ($key === null) {
            $connection?->abort();

            return false;
        }
        unset($this->active[$id]);
        if ($connection !== null) {
            if ($this->closed) {
                $connection->abort();
            } else {
                $this->idle->store($key, $connection);
            }
        }
        $this->dispatch();

        return true;
    }

    /** Number of bounded FIFO acquisitions awaiting a lease permit. */
    public function waitingCount(): int
    {
        return count($this->waiting);
    }

    private function dispatch(): void
    {
        while (!$this->closed && count($this->active) < $this->maxActive && $this->waiting !== []) {
            $id = array_key_first($this->waiting);
            $entry = $this->waiting[$id];
            unset($this->waiting[$id]);
            $this->loop->cancel($entry['timer']);
            $this->grant($id, $entry['key'], $entry['callback']);
        }
    }

    private function grant(int $id, PoolIsolationKey $key, Closure $callback): void
    {
        $connection = $this->idle->take($key);
        $this->active[$id] = $key;

        try {
            $callback($id, $connection, null);
        } catch (Throwable $error) {
            $connection?->abort();
            $this->release($id);

            throw $error;
        }
    }

    private function rejectWaiter(int $id, RuntimeException $error): void
    {
        $entry = $this->waiting[$id] ?? null;
        if ($entry === null) {
            return;
        }
        unset($this->waiting[$id]);
        $this->loop->cancel($entry['timer']);
        ($entry['callback'])($id, null, $error);
    }
}
