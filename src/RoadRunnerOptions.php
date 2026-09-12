<?php

declare(strict_types=1);

namespace Infocyph\Runwire;

use InvalidArgumentException;

final readonly class RoadRunnerOptions
{
    public function __construct(
        public int $maxRequestBodyBytes = 16_777_216,
        public int $maxResponseBytes = 16_777_216,
    ) {
        if ($maxRequestBodyBytes < 1) {
            throw new InvalidArgumentException('RoadRunner maximum request body size must be positive.');
        }
        if ($maxResponseBytes < 1) {
            throw new InvalidArgumentException('RoadRunner maximum response body size must be positive.');
        }
    }
}
