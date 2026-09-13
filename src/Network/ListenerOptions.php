<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Network;

use InvalidArgumentException;

final readonly class ListenerOptions
{
    /** @var array<string, mixed> */
    public array $socketContext;

    /** @param array<string, mixed> $socketContext */
    public function __construct(
        public int $backlog = 128,
        public int $maxConnections = 10_000,
        public int $acceptBatchSize = 32,
        array $socketContext = [],
        public bool $reusePort = false,
    ) {
        if ($backlog <= 0 || $maxConnections <= 0 || $acceptBatchSize <= 0) {
            throw new InvalidArgumentException('Listener backlog, maxConnections and acceptBatchSize must be positive.');
        }

        $reuseContext = $socketContext['so_reuseport'] ?? $socketContext['so_reuse_port'] ?? null;
        if ($reuseContext !== null && (!$reusePort || $reuseContext !== true)) {
            throw new InvalidArgumentException('Configure SO_REUSEPORT through ListenerOptions::reusePort.');
        }
        if ($reusePort && !SocketCapabilityProbe::supportsReusePort()) {
            throw new InvalidArgumentException('SO_REUSEPORT was requested but is unavailable on this runtime.');
        }

        unset($socketContext['so_reuse_port']);
        $this->socketContext = $reusePort
            ? [...$socketContext, 'so_reuseport' => true]
            : $socketContext;
    }
}
