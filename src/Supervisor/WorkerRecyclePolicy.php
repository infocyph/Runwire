<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Supervisor;

use InvalidArgumentException;

final readonly class WorkerRecyclePolicy
{
    private const int MAX_REQUESTS = 10_000_000;

    private const int MAX_LIFETIME_SECONDS = 31_536_000;

    public function __construct(
        public int $maxRequests = 0,
        public int $maxLifetimeSeconds = 0,
        public int $maxMemoryBytes = 0,
        public int $jitterRequests = 0,
        public int $jitterSeconds = 0,
        public float $gracefulTimeoutSeconds = 10.0,
    ) {
        if ($maxRequests < 0 || $maxRequests > self::MAX_REQUESTS) {
            throw new InvalidArgumentException('Worker recycle maxRequests must be between 0 and 10000000.');
        }
        if ($maxLifetimeSeconds < 0 || $maxLifetimeSeconds > self::MAX_LIFETIME_SECONDS) {
            throw new InvalidArgumentException('Worker recycle maxLifetimeSeconds must be between 0 and 31536000.');
        }
        if ($maxMemoryBytes < 0) {
            throw new InvalidArgumentException('Worker recycle maxMemoryBytes must be zero or positive.');
        }
        if ($jitterRequests < 0 || $jitterRequests > self::MAX_REQUESTS) {
            throw new InvalidArgumentException('Worker recycle jitterRequests must be between 0 and 10000000.');
        }
        if ($jitterSeconds < 0 || $jitterSeconds > self::MAX_LIFETIME_SECONDS) {
            throw new InvalidArgumentException('Worker recycle jitterSeconds must be between 0 and 31536000.');
        }
        if ($maxRequests === 0 && $jitterRequests !== 0) {
            throw new InvalidArgumentException('Worker recycle jitterRequests requires maxRequests to be enabled.');
        }
        if ($maxLifetimeSeconds === 0 && $jitterSeconds !== 0) {
            throw new InvalidArgumentException('Worker recycle jitterSeconds requires maxLifetimeSeconds to be enabled.');
        }
        if (!is_finite($gracefulTimeoutSeconds) || $gracefulTimeoutSeconds <= 0 || $gracefulTimeoutSeconds > 3_600.0) {
            throw new InvalidArgumentException('Worker recycle gracefulTimeoutSeconds must be finite and between 0 and 3600 seconds.');
        }
    }

    public function enabled(): bool
    {
        return $this->maxRequests > 0 || $this->maxLifetimeSeconds > 0 || $this->maxMemoryBytes > 0;
    }
}
