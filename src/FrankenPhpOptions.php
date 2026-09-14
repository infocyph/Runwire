<?php

declare(strict_types=1);

namespace Infocyph\Runwire;

use Infocyph\Runwire\Runtime\Enum\FrankenPhpMode;
use InvalidArgumentException;

/**
 * Configures FrankenPHP execution mode and payload limits.
 */
final readonly class FrankenPhpOptions
{
    /**
     * Create validated FrankenPHP runtime options.
     */
    public function __construct(
        public FrankenPhpMode $mode = FrankenPhpMode::AUTO,
        public int $maxRequestBodyBytes = 16_777_216,
        public int $maxResponseBytes = 16_777_216,
    ) {
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
