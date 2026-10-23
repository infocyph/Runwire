<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Network;

use InvalidArgumentException;
use RuntimeException;

/**
 * Secure outbound TLS policy, deliberately separate from server identity options.
 *
 * Peer chain and hostname verification are mandatory. OpenSSL is optional
 * for plain TCP, but required explicitly whenever this policy is applied.
 */
final readonly class TlsClientOptions
{
    /**
     * @param list<string> $alpnProtocols
     */
    public function __construct(
        public string $peerName,
        public ?string $caFile = null,
        public ?string $caPath = null,
        public array $alpnProtocols = ['h2', 'http/1.1'],
        public float $handshakeTimeoutSeconds = 10.0,
    ) {
        self::validateIdentity($peerName, $caFile, $caPath);
        self::validateProtocols($alpnProtocols);
        if (!is_finite($handshakeTimeoutSeconds) || $handshakeTimeoutSeconds <= 0) {
            throw new InvalidArgumentException('Client TLS handshake timeout must be finite and positive.');
        }
    }

    /**
     * Secure stream context: no opt-out of peer or hostname verification.
     *
     * @return array<string, bool|int|string>
     */
    public function context(): array
    {
        if (!extension_loaded('openssl')) {
            throw new RuntimeException('Client TLS requires ext-openssl; no plaintext downgrade is allowed.');
        }

        $options = [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'peer_name' => $this->peerName,
            'SNI_enabled' => true,
            'SNI_server_name' => $this->peerName,
            'allow_self_signed' => false,
            'capture_peer_cert' => true,
            'disable_compression' => true,
            'verify_depth' => 8,
            'crypto_method' => $this->method(),
        ];
        if ($this->caFile !== null) {
            $options['cafile'] = $this->caFile;
        }
        if ($this->caPath !== null) {
            $options['capath'] = $this->caPath;
        }
        if ($this->alpnProtocols !== []) {
            $options['alpn_protocols'] = implode(',', $this->alpnProtocols);
        }

        return $options;
    }

    /** Accept TLS 1.2 and 1.3 client methods only, never legacy SSL methods. */
    public function method(): int
    {
        if (!defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')
            || !defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) {
            throw new RuntimeException('TLS 1.2/1.3 OpenSSL crypto methods are unavailable.');
        }

        return STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
    }
    private static function validateIdentity(string $peerName, ?string $caFile, ?string $caPath): void
    {
        if ($peerName === '' || strlen($peerName) > 253
            || str_contains($peerName, "\0") || preg_match('/\s/', $peerName)) {
            throw new InvalidArgumentException('Client TLS peer hostname must be nonempty and valid.');
        }
        if ($caFile !== null && ($caFile === '' || !is_file($caFile))) {
            throw new InvalidArgumentException('Client TLS CA file must exist.');
        }
        if ($caPath !== null && ($caPath === '' || !is_dir($caPath))) {
            throw new InvalidArgumentException('Client TLS CA directory must exist.');
        }
    }

    /** @param list<string> $alpnProtocols */
    private static function validateProtocols(array $alpnProtocols): void
    {
        foreach ($alpnProtocols as $protocol) {
            if ($protocol === '' || strlen($protocol) > 255
                || str_contains($protocol, ',') || str_contains($protocol, "\0")) {
                throw new InvalidArgumentException('Client TLS ALPN identifiers must be distinct, valid tokens.');
            }
        }
        if (count(array_unique($alpnProtocols)) !== count($alpnProtocols)) {
            throw new InvalidArgumentException('Client TLS ALPN identifiers must not repeat.');
        }
    }
}
