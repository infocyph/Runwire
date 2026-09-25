<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Network;

use InvalidArgumentException;

/**
 * Configures server-side TLS certificates, ALPN, and handshake behavior.
 */
final readonly class TlsOptions
{
    /**
     * @param list<string> $alpnProtocols
     * @param array<string, mixed> $extraContext
     */
    public function __construct(
        public string $localCertificate,
        public ?string $privateKey = null,
        public ?string $passphrase = null,
        public array $alpnProtocols = ['h2', 'http/1.1'],
        public float $handshakeTimeoutSeconds = 10.0,
        public ?int $cryptoMethod = null,
        public array $extraContext = [],
    ) {
        if ($localCertificate === '' || !is_file($localCertificate)) {
            throw new InvalidArgumentException('TLS local certificate must reference an existing file.');
        }
        if ($privateKey !== null && ($privateKey === '' || !is_file($privateKey))) {
            throw new InvalidArgumentException('TLS private key must reference an existing file when supplied.');
        }
        if (!is_finite($handshakeTimeoutSeconds) || $handshakeTimeoutSeconds <= 0) {
            throw new InvalidArgumentException('TLS handshake timeout must be finite and positive.');
        }
        foreach ($alpnProtocols as $protocol) {
            if ($protocol === '' || strlen($protocol) > 255 || str_contains($protocol, ',') || str_contains($protocol, "\0")) {
                throw new InvalidArgumentException('Every ALPN protocol must be a non-empty string of at most 255 bytes.');
            }
        }
        if (count(array_unique($alpnProtocols)) !== count($alpnProtocols)) {
            throw new InvalidArgumentException('ALPN protocols must be unique.');
        }
    }

    /** @return array<string, mixed> */
    public function context(): array
    {
        $context = $this->extraContext;
        $context['local_cert'] = realpath($this->localCertificate) ?: $this->localCertificate;
        if ($this->privateKey !== null) {
            $context['local_pk'] = realpath($this->privateKey) ?: $this->privateKey;
        }
        if ($this->passphrase !== null) {
            $context['passphrase'] = $this->passphrase;
        }
        $context['crypto_method'] = $this->method();
        $context['verify_peer'] ??= false;
        $context['verify_peer_name'] ??= false;
        $context['disable_compression'] = true;
        if ($this->alpnProtocols !== []) {
            $context['alpn_protocols'] = implode(',', $this->alpnProtocols);
        }

        return $context;
    }

    /**
     * Returns the OpenSSL crypto method used for server handshakes.
     */
    public function method(): int
    {
        return $this->cryptoMethod ?? (STREAM_CRYPTO_METHOD_TLSv1_2_SERVER | STREAM_CRYPTO_METHOD_TLSv1_3_SERVER);
    }
}
