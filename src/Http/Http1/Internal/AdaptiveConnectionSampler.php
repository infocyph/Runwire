<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Http1\Internal;

use Infocyph\Runwire\Http\Internal\AdaptiveLoadSample;
use Infocyph\Runwire\Network\Connection;
use InvalidArgumentException;

/**
 * Maintains a bounded recent-connection sample for HTTP/1.1 AUTO admission policy.
 *
 * @internal
 */
final class AdaptiveConnectionSampler
{
    /** @var array<int, Connection> */
    private array $connections = [];

    /**
     * Create a bounded worker-local connection sampler.
     */
    public function __construct(
        private readonly int $activeCapacity,
        private readonly int $maxSamples = 64,
        private readonly int $queueBytesPerConnection = 262_144,
    ) {
        if ($activeCapacity < 1 || $maxSamples < 1 || $queueBytesPerConnection < 1) {
            throw new InvalidArgumentException('Adaptive connection sampler bounds must be positive.');
        }
    }

    /**
     * Add a live connection to the bounded recent sample.
     */
    public function add(Connection $connection): void
    {
        $id = spl_object_id($connection);
        $this->connections[$id] = $connection;
        if (count($this->connections) <= $this->maxSamples) {
            return;
        }

        unset($this->connections[array_key_first($this->connections)]);
    }

    /**
     * Remove a closed connection from the sample when present.
     */
    public function remove(Connection $connection): void
    {
        unset($this->connections[spl_object_id($connection)]);
    }

    /**
     * Build one bounded worker load sample.
     */
    public function sample(int $activeConnections): AdaptiveLoadSample
    {
        if ($activeConnections < 0) {
            throw new InvalidArgumentException('Active connection count cannot be negative.');
        }

        $queuedBytes = 0;
        $pressured = false;
        foreach ($this->connections as $connection) {
            $queuedBytes += min(
                $connection->pendingWriteBytes(),
                $this->queueBytesPerConnection,
            );
            $pressured = $pressured || $connection->isWritePressured();
        }

        $sampleCount = max(1, count($this->connections));

        return AdaptiveLoadSample::fromCounters(
            pressured: $pressured,
            queuedBytes: $queuedBytes,
            queueCapacityBytes: $sampleCount * $this->queueBytesPerConnection,
            activeWork: $activeConnections,
            activeCapacity: $this->activeCapacity,
        );
    }

    /**
     * Return the number of live connections retained for bounded sampling.
     */
    public function sampleSize(): int
    {
        return count($this->connections);
    }
}
