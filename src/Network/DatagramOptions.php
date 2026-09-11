<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Network;

use InvalidArgumentException;

final readonly class DatagramOptions
{
    /** @param array<string, mixed> $socketContext */
    public function __construct(
        public int $maxDatagramBytes = 65_507,
        public int $receiveBatchSize = 32,
        public array $socketContext = [],
    ) {
        if ($maxDatagramBytes <= 0 || $maxDatagramBytes > 65_507) {
            throw new InvalidArgumentException('Maximum UDP datagram size must be between 1 and 65507 bytes.');
        }
        if ($receiveBatchSize <= 0 || $receiveBatchSize > 4_096) {
            throw new InvalidArgumentException('UDP receive batch size must be between 1 and 4096.');
        }
    }
}
