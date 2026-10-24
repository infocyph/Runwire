<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Network;

use Infocyph\Runwire\Loop\LoopInterface;
use Infocyph\Runwire\Network\Enum\ConnectionState;
use InvalidArgumentException;
use Throwable;

/**
 * Experimental bounded store of already verified and completely quiescent
 * idle connections. It does not open sockets, handle waiters, or decide when
 * an HTTP response is complete; an active pool remains a separate 3.0 task.
 */
final class IdleConnectionPool
{
    private bool $closed = false;

    /** @var array<int, array{key:string, connection:Connection, observer:?int, timer:?int}> */
    private array $entries = [];

    private int $nextId = 1;

    /** Set finite idle retention and hard connection admission limit. */
    public function __construct(
        private readonly LoopInterface $loop,
        private readonly int $maxIdle = 16,
        private readonly float $idleTtlSeconds = 30.0,
    ) {
        if ($maxIdle < 1 || $maxIdle > 65_536
            || !is_finite($idleTtlSeconds) || $idleTtlSeconds <= 0) {
            throw new InvalidArgumentException('Idle connection retention limits must be positive and finite.');
        }
    }

    /** Close the store and all borrowed-but-not-yet-issued idle sockets. */
    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        foreach (array_keys($this->entries) as $id) {
            $this->discard($id);
        }
    }

    /** Number of connections retained in the pool. */
    public function count(): int
    {
        return count($this->entries);
    }

    /**
     * Retain an already verified stream only when its previous protocol owner
     * has released every callback/byte. Failure closes the offered socket.
     */
    public function store(PoolIsolationKey $key, Connection $connection): bool
    {
        if ($this->closed || !$connection->isPoolIdleSafe()) {
            $connection->abort();

            return false;
        }
        if ($this->nextId === PHP_INT_MAX) {
            $connection->abort();

            return false;
        }
        while (count($this->entries) >= $this->maxIdle) {
            $oldest = array_key_first($this->entries);
            if ($oldest === null) {
                break;
            }
            $this->discard($oldest);
        }

        $id = $this->nextId++;
        $this->entries[$id] = [
            'key' => $key->id(),
            'connection' => $connection,
            'observer' => null,
            'timer' => null,
        ];
        try {
            $connection->handoffCallbacks(
                $this,
                function (): void {
                    $this->discard($id);
                },
                static function (): void {
                },
                function (): void {
                    $this->discard($id);
                },
            );
            if (!isset($this->entries[$id])) {
                return false;
            }
            $this->entries[$id]['observer'] = $connection->registerCloseObserver(function (): void {
                $this->discard($id);
            });
            if (!isset($this->entries[$id])) {
                return false;
            }
            $this->entries[$id]['timer'] = $this->loop->delay($this->idleTtlSeconds, function (): void {
                $this->discard($id);
            });
        } catch (Throwable $error) {
            $this->discard($id);
            $connection->abort();

            throw $error;
        }

        return true;
    }

    /**
     * Borrow one healthy, identity-matched connection; never hand back stale
     * or unverified buffer state even when the peer wrote during idle time.
     */
    public function take(PoolIsolationKey $key): ?Connection
    {
        if ($this->closed) {
            return null;
        }

        foreach ($this->entries as $id => $entry) {
            if ($entry['key'] !== $key->id()) {
                continue;
            }
            $connection = $this->release($id);
            if ($connection?->isPoolIdleSafe()) {
                return $connection;
            }
            $connection?->abort();
        }

        return null;
    }

    private function discard(int $id): void
    {
        $this->release($id)?->abort();
    }

    private function release(int $id): ?Connection
    {
        $entry = $this->entries[$id] ?? null;
        if ($entry === null) {
            return null;
        }
        unset($this->entries[$id]);
        if ($entry['timer'] !== null) {
            $this->loop->cancel($entry['timer']);
        }
        $connection = $entry['connection'];
        if ($entry['observer'] !== null) {
            $connection->unregisterCloseObserver($entry['observer']);
        }
        $connection->releaseCallbacks($this);

        return $connection->state() === ConnectionState::OPEN ? $connection : null;
    }
}
