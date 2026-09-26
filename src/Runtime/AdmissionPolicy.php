<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Runtime;

use InvalidArgumentException;

/**
 * Defines request, connection, and stream admission ceilings for a worker.
 */
final readonly class AdmissionPolicy
{
    private const int MAX_LIMIT = 1_000_000;

    /**
     * Creates an admission policy; zero disables count limits while queued bytes remain bounded.
     */
    public function __construct(
        public int $maxActiveRequests = 256,
        public int $maxConcurrentConnections = 0,
        public int $maxStreamsPerWorker = 256,
        public int $retryAfterSeconds = 1,
        public int $maxQueuedBytes = 67_108_864,
    ) {
        foreach ([
            'maxActiveRequests' => $maxActiveRequests,
            'maxConcurrentConnections' => $maxConcurrentConnections,
            'maxStreamsPerWorker' => $maxStreamsPerWorker,
        ] as $name => $value) {
            if ($value < 0 || $value > self::MAX_LIMIT) {
                throw new InvalidArgumentException(sprintf(
                    '%s must be between 0 and %d, where 0 disables the limit.',
                    $name,
                    self::MAX_LIMIT,
                ));
            }
        }

        if ($maxQueuedBytes <= 0 || $maxQueuedBytes > 1_073_741_824) {
            throw new InvalidArgumentException('maxQueuedBytes must be between 1 and 1073741824.');
        }

        if ($retryAfterSeconds < 0 || $retryAfterSeconds > 3_600) {
            throw new InvalidArgumentException('retryAfterSeconds must be between 0 and 3600.');
        }
    }

    /**
     * Applies the configured connection ceiling to a fallback limit.
     */
    public function connectionLimit(int $fallback): int
    {
        return $this->maxConcurrentConnections > 0
            ? min($fallback, $this->maxConcurrentConnections)
            : $fallback;
    }

    /**
     * Reports whether any admission limit is enabled.
     */
    public function enabled(): bool
    {
        return $this->maxActiveRequests > 0
            || $this->maxConcurrentConnections > 0
            || $this->maxStreamsPerWorker > 0
            || $this->maxQueuedBytes > 0;
    }
}
