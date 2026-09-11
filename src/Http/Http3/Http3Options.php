<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Http3;

use Infocyph\Runwire\Network\TlsOptions;
use InvalidArgumentException;

final readonly class Http3Options
{
    public function __construct(
        public Http3Limits $limits = new Http3Limits(),
        public float $pollTimeoutSeconds = 0.05,
    ) {
        if (!is_finite($pollTimeoutSeconds) || $pollTimeoutSeconds <= 0 || $pollTimeoutSeconds > 1.0) {
            throw new InvalidArgumentException('HTTP/3 poll timeout must be finite and between 0 and 1 second.');
        }
    }

    /** @return array<string, bool|string> */
    public function listenerOptions(TlsOptions $tls): array
    {
        return [
            'local_cert' => self::resolvedPath($tls->localCertificate),
            ...($tls->privateKey === null ? [] : ['local_pk' => self::resolvedPath($tls->privateKey)]),
            ...($tls->passphrase === null ? [] : ['passphrase' => $tls->passphrase]),
            'alpn' => 'h3',
            'reuse_port' => true,
        ];
    }

    private static function resolvedPath(string $path): string
    {
        return realpath($path) ?: $path;
    }
}
