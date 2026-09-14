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
     * Creates an admission policy, where zero disables each corresponding limit.
     */
    public function __construct(
        public int $maxActiveRequests = 0,
        public int $maxConcurrentConnections = 0,
        public int $maxStreamsPerWorker = 0,
        public int $retryAfterSeconds = 1,
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
            || $this->maxStreamsPerWorker > 0;
    }
}
