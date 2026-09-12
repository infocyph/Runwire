<?php

declare(strict_types=1);

namespace Infocyph\Runwire;

use InvalidArgumentException;

final readonly class FrankenPhpOptions
{
    public function __construct(
        public FrankenPhpMode $mode = FrankenPhpMode::AUTO,
        public int $maxRequests = 0,
        public int $maxRequestBodyBytes = 16_777_216,
        public int $maxResponseBytes = 16_777_216,
    ) {
        if ($maxRequests < 0 || $maxRequests > 10_000_000) {
            throw new InvalidArgumentException('FrankenPHP maxRequests must be between 0 and 10000000.');
        }
        self::validateLimit($maxRequestBodyBytes, 'FrankenPHP request body');
        self::validateLimit($maxResponseBytes, 'FrankenPHP response');
    }

    private static function validateLimit(int $bytes, string $name): void
    {
        if ($bytes < 1 || $bytes > 1_073_741_824) {
            throw new InvalidArgumentException(sprintf('%s limit must be between 1 byte and 1 GiB.', $name));
        }
    }
}
