<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Network;

use InvalidArgumentException;

final readonly class ListenerOptions
{
    /** @param array<string, mixed> $socketContext */
    public function __construct(
        public int $backlog = 128,
        public int $maxConnections = 10_000,
        public int $acceptBatchSize = 32,
        public array $socketContext = [],
    ) {
        if ($backlog <= 0 || $maxConnections <= 0 || $acceptBatchSize <= 0) {
            throw new InvalidArgumentException('Listener backlog, maxConnections and acceptBatchSize must be positive.');
        }
    }
}
