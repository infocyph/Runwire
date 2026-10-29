<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Network;

use Closure;
use Infocyph\Runwire\Loop\LoopInterface;
use InvalidArgumentException;
use LogicException;
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

    private readonly int $ownerPid;

    /** @var array<int, PoolIsolationKey> */
    private array $active = [];

    private bool $closed = false;

    /** @var array<string, int> */
    private array $metricCounts = [
        'acquisitions' => 0,
        'reuse_hits' => 0,
        'fresh_dial_slots' => 0,
        'queued' => 0,
        'waiter_grants' => 0,
        'waiter_timeouts' => 0,
        'waiter_cancellations' => 0,
        'waiter_closed' => 0,
        'waiter_overflow' => 0,
        'releases' => 0,
        'idle_retained' => 0,
        'idle_rejected' => 0,
    ];

    private int $nextId = 1;

    /** @var array<int, array{key:PoolIsolationKey, callback:Closure, timer:int}> */
    private array $waiting = [];

    /** Bound simultaneous active ownership, waiters and idle retention. */
    public function __construct(
        private readonly LoopInterface $loop,
        private readonly int $maxActive = 16,
        private readonly int $maxWaiting = 128,
        ?int $maxIdle = null,
        float $idleTtlSeconds = 30.0,
        private readonly bool $enableMetrics = false,
    ) {
        $maxIdle ??= min($maxActive, 16);
        if ($maxActive < 1 || $maxActive > 65_536 || $maxWaiting < 1
            || $maxWaiting > 65_536 || $maxIdle < 1 || $maxIdle > $maxActive) {
            throw new InvalidArgumentException('Connection pool lease and waiter limits are invalid.');
        }

        $pid = getmypid();
        if (!is_int($pid)) {
            throw new RuntimeException('Unable to establish connection pool process ownership.');
        }
        $this->ownerPid = $pid;
        $this->idle = new IdleConnectionPool($loop, $maxIdle, $idleTtlSeconds);
    }

    /**
     * Request an existing healthy socket or a fresh dial slot; no busy retry.
     * @param callable(int,?Connection,?Throwable):void $ready
     */
    public function acquire(PoolIsolationKey $key, callable $ready, float $waitSeconds = 10.0): int
    {
        $this->assertProcessOwner();
        if ($this->closed || !is_finite($waitSeconds) || $waitSeconds <= 0
            || $this->nextId === PHP_INT_MAX) {
            throw new InvalidArgumentException('Pool is closed or waiter deadline/identity is invalid.');
        }
        $id = $this->nextId++;
        if ($this->enableMetrics) {
            ++$this->metricCounts['acquisitions'];
        }
        $callback = Closure::fromCallable($ready);
        if (count($this->active) < $this->maxActive && $this->waiting === []) {
            $this->grant($id, $key, $callback);

            return $id;
        }
        if (count($this->waiting) >= $this->maxWaiting) {
            if ($this->enableMetrics) {
                ++$this->metricCounts['waiter_overflow'];
            }

            throw new OverflowException('Connection pool waiter quota exceeded.');
        }

        $timer = $this->loop->delay($waitSeconds, function () use ($id): void {
            if ($this->enableMetrics && isset($this->waiting[$id])) {
                ++$this->metricCounts['waiter_timeouts'];
            }
            $this->rejectWaiter($id, new RuntimeException('Connection pool waiter timed out.'));
        });
        $this->waiting[$id] = ['key' => $key, 'callback' => $callback, 'timer' => $timer];
        if ($this->enableMetrics) {
            ++$this->metricCounts['queued'];
        }

        return $id;
    }

    /** Number of leased (including pending dial) active connection slots. */
    public function activeCount(): int
    {
        $this->assertProcessOwner();

        return count($this->active);
    }

    /** Cancel a queued waiter exactly once; active leases use release(). */
    public function cancel(int $id): bool
    {
        $this->assertProcessOwner();
        if (!isset($this->waiting[$id])) {
            return false;
        }
        if ($this->enableMetrics) {
            ++$this->metricCounts['waiter_cancellations'];
        }
        $this->rejectWaiter($id, new RuntimeException('Connection pool waiter was canceled.'));

        return true;
    }

    /** Shut down all idle connections and settle queued waiters. Active leases are caller-owned. */
    public function close(): void
    {
        $this->assertProcessOwner();
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        $waiters = $this->waiting;
        $this->waiting = [];
        if ($this->enableMetrics) {
            $this->metricCounts['waiter_closed'] += count($waiters);
        }
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
        $this->assertProcessOwner();

        return $this->idle->count();
    }

    /**
     * Snapshot optionally enabled bounded counters without exposing tenant/proxy credentials.
     *
     * @return array<string, int|bool>
     */
    public function metrics(): array
    {
        $this->assertProcessOwner();

        return [
            'enabled' => $this->enableMetrics,
            ...$this->metricCounts,
            'active' => count($this->active),
            'waiting' => count($this->waiting),
            'idle' => $this->idle->count(),
        ];
    }

    /** Retire a lease; unhealthy, foreign or closed-pool sockets are aborted. */
    public function release(int $id, ?Connection $connection = null): bool
    {
        $this->assertProcessOwner();
        $key = $this->active[$id] ?? null;
        if ($key === null) {
            $connection?->abort();

            return false;
        }
        unset($this->active[$id]);
        if ($this->enableMetrics) {
            ++$this->metricCounts['releases'];
        }
        if ($connection !== null) {
            $this->retainReleasedConnection($key, $connection);
        }
        $this->dispatch();

        return true;
    }

    /** Number of bounded FIFO acquisitions awaiting a lease permit. */
    public function waitingCount(): int
    {
        $this->assertProcessOwner();

        return count($this->waiting);
    }

    private function assertProcessOwner(): void
    {
        if (getmypid() !== $this->ownerPid) {
            throw new LogicException('Connection pools cannot be used by a forked child; create a pool after forking.');
        }
    }

    private function dispatch(): void
    {
        while (!$this->closed && count($this->active) < $this->maxActive && $this->waiting !== []) {
            $id = array_key_first($this->waiting);
            $entry = $this->waiting[$id];
            unset($this->waiting[$id]);
            $this->loop->cancel($entry['timer']);
            if ($this->enableMetrics) {
                ++$this->metricCounts['waiter_grants'];
            }
            $this->grant($id, $entry['key'], $entry['callback']);
        }
    }

    private function grant(int $id, PoolIsolationKey $key, Closure $callback): void
    {
        $connection = $this->idle->take($key);
        if ($this->enableMetrics) {
            ++$this->metricCounts[$connection === null ? 'fresh_dial_slots' : 'reuse_hits'];
        }
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

    /** Preserve socket ownership and bounded opt-in accounting after lease retirement. */
    private function retainReleasedConnection(PoolIsolationKey $key, Connection $connection): void
    {
        if ($this->closed) {
            $connection->abort();

            return;
        }

        $retained = $this->idle->store($key, $connection);
        if ($this->enableMetrics) {
            ++$this->metricCounts[$retained ? 'idle_retained' : 'idle_rejected'];
        }
    }
}
