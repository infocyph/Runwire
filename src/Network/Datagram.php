<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Network;

final readonly class Datagram
{
    public function __construct(
        public string $payload,
        public string $peerAddress,
        public ?string $localAddress,
    ) {
    }
}
