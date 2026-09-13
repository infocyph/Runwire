<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Metrics;

use InvalidArgumentException;

final readonly class GcPolicy
{
    public function __construct(
        public bool $enabled = true,
        public int $requestInterval = 256,
        public int $growthBytes = 16_777_216,
        public float $minimumIntervalSeconds = 5.0,
    ) {
        if ($requestInterval < 1 || $requestInterval > 1_000_000) {
            throw new InvalidArgumentException('GC request interval must be between 1 and 1000000.');
        }
        if ($growthBytes < 0 || $growthBytes > 8_589_934_592) {
            throw new InvalidArgumentException('GC growth threshold must be between 0 and 8589934592 bytes.');
        }
        if (!is_finite($minimumIntervalSeconds) || $minimumIntervalSeconds < 0 || $minimumIntervalSeconds > 3_600.0) {
            throw new InvalidArgumentException('GC minimum interval must be finite and between 0 and 3600 seconds.');
        }
    }
}
