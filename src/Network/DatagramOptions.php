<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Network;

use InvalidArgumentException;

final readonly class DatagramOptions
{
    /** @var array<string, mixed> */
    public array $socketContext;

    /** @param array<string, mixed> $socketContext */
    public function __construct(
        public int $maxDatagramBytes = 65_507,
        public int $receiveBatchSize = 32,
        array $socketContext = [],
        public bool $reusePort = false,
    ) {
        if ($maxDatagramBytes <= 0 || $maxDatagramBytes > 65_507) {
            throw new InvalidArgumentException('Maximum UDP datagram size must be between 1 and 65507 bytes.');
        }
        if ($receiveBatchSize <= 0 || $receiveBatchSize > 4_096) {
            throw new InvalidArgumentException('UDP receive batch size must be between 1 and 4096.');
        }

        $reuseContext = $socketContext['so_reuseport'] ?? $socketContext['so_reuse_port'] ?? null;
        if ($reuseContext !== null && (!$reusePort || $reuseContext !== true)) {
            throw new InvalidArgumentException('Configure SO_REUSEPORT through DatagramOptions::reusePort.');
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
