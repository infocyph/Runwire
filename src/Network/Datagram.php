<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Network;

/**
 * Carries a received datagram with peer and local addressing metadata.
 */
final readonly class Datagram
{
    /**
     * Create a datagram value object.
     */
    public function __construct(
        public string $payload,
        public string $peerAddress,
        public ?string $localAddress,
    ) {}
}
