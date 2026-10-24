<?php

declare(strict_types=1);

namespace Infocyph\Runwire;

use InvalidArgumentException;

/**
 * Configures request and response size limits for FPM execution.
 */
final readonly class FpmOptions
{
    /**
     * Create validated FPM runtime limits.
     */
    public function __construct(
        public int $maxRequestBodyBytes = 16_777_216,
        public int $maxResponseBytes = 16_777_216,
    ) {
        self::validateLimit($maxRequestBodyBytes, 'FPM request body');
        self::validateLimit($maxResponseBytes, 'FPM response');
    }

    private static function validateLimit(int $bytes, string $name): void
    {
        if ($bytes < 1 || $bytes > 1_073_741_824) {
            throw new InvalidArgumentException(sprintf('%s limit must be between 1 byte and 1 GiB.', $name));
        }
    }
}
