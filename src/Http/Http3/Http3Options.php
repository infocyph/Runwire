<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Http3;

use Infocyph\Runwire\Network\TlsOptions;
use InvalidArgumentException;

/**
 * Defines HTTP/3 listener, polling, handshake, and resource-limit options.
 */
final readonly class Http3Options
{
    public const bool ZERO_RTT_ENABLED = false;

    /**
     * Create and validate HTTP/3 runtime options.
     */
    public function __construct(
        public Http3Limits $limits = new Http3Limits(),
        public float $pollTimeoutSeconds = 0.05,
        public float $handshakeTimeoutSeconds = 10.0,
    ) {
        if (!is_finite($pollTimeoutSeconds) || $pollTimeoutSeconds <= 0 || $pollTimeoutSeconds > 1.0) {
            throw new InvalidArgumentException('HTTP/3 poll timeout must be finite and between 0 and 1 second.');
        }
        if (!is_finite($handshakeTimeoutSeconds) || $handshakeTimeoutSeconds <= 0 || $handshakeTimeoutSeconds > 60.0) {
            throw new InvalidArgumentException('HTTP/3 handshake timeout must be finite and between 0 and 60 seconds.');
        }
    }

    /** @return array<string, bool|string> */
    public function listenerOptions(TlsOptions $tls, bool $reusePort = false): array
    {
        return [
            'local_cert' => self::resolvedPath($tls->localCertificate),
            ...($tls->privateKey === null ? [] : ['local_pk' => self::resolvedPath($tls->privateKey)]),
            ...($tls->passphrase === null ? [] : ['passphrase' => $tls->passphrase]),
            'alpn' => 'h3',
            'reuse_port' => $reusePort,
        ];
    }

    private static function resolvedPath(string $path): string
    {
        return realpath($path) ?: $path;
    }
}
