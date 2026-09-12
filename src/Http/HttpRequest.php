<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http;

use Infocyph\Runwire\Http\Enum\ProtocolVersion;

final readonly class HttpRequest
{
    public function __construct(
        public string $method,
        public string $target,
        public ProtocolVersion $version,
        public Headers $headers,
        public RequestBodyInterface $body,
        public ?string $peerAddress = null,
        public ?string $localAddress = null,
        public bool $encrypted = false,
    ) {}
}
