<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Client\Internal;

use Infocyph\Runwire\Http\Client\ClientRequest;
use Infocyph\Runwire\Http\Enum\ProtocolVersion;
use Infocyph\Runwire\Network\QuicClientOptions;
use InvalidArgumentException;

/** @internal Rejects cross-origin and downgrade attempts before any native QUIC I/O. */
final readonly class Http3ClientOrigin
{
    /** Validate the TLS peer and h3 ALPN against one immutable origin. @return array{host:string,port:int} */
    public static function validate(ClientRequest $request, QuicClientOptions $options): array
    {
        $parts = parse_url($request->uri);
        if (!is_array($parts) || ($parts['scheme'] ?? null) !== 'https'
            || !is_string($parts['host'] ?? null)) {
            throw new InvalidArgumentException('HTTP/3 requires a secure absolute HTTPS origin.');
        }
        $host = trim($parts['host'], '[]');
        if (strcasecmp($options->peerName, $host) !== 0 || $options->alpnProtocols !== ['h3']) {
            throw new InvalidArgumentException('HTTP/3 QUIC must verify the original origin with h3-only ALPN.');
        }
        if ($request->protocol->negotiate('h3', true, true) !== ProtocolVersion::HTTP_3) {
            throw new InvalidArgumentException('HTTP/3 client protocol policy rejected h3.');
        }

        return ['host' => $host, 'port' => $parts['port'] ?? 443];
    }

}
