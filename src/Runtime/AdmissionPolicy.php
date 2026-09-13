<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Runtime;

use InvalidArgumentException;

final readonly class AdmissionPolicy
{
    private const int MAX_LIMIT = 1_000_000;

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

    public function connectionLimit(int $fallback): int
    {
        return $this->maxConcurrentConnections > 0
            ? min($fallback, $this->maxConcurrentConnections)
            : $fallback;
    }

    public function enabled(): bool
    {
        return $this->maxActiveRequests > 0
            || $this->maxConcurrentConnections > 0
            || $this->maxStreamsPerWorker > 0;
    }
}
