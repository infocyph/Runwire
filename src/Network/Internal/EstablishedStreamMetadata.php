<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Network\Internal;

use InvalidArgumentException;
use OpenSSLCertificate;

/**
 * Resolves established socket metadata once, after the transport handshake.
 *
 * @internal
 */
final class EstablishedStreamMetadata
{
    /**
     * @param resource $stream
     * @return array{peer:?string, local:?string, protocol:?string, encrypted:bool, fingerprint:?string}
     */
    public static function capture(
        mixed $stream,
        ?string $peerAddress,
        ?string $localAddress,
        ?string $negotiatedProtocol,
        bool $encrypted,
    ): array {
        if (!is_resource($stream) || get_resource_type($stream) !== 'stream') {
            throw new InvalidArgumentException('An established stream resource is required.');
        }

        $metadata = stream_get_meta_data($stream);
        $crypto = $metadata['crypto'] ?? null;
        if (!is_array($crypto)) {
            $crypto = [];
        }

        return [
            'peer' => $peerAddress ?? self::socketAddress($stream, true),
            'local' => $localAddress ?? self::socketAddress($stream, false),
            'protocol' => $negotiatedProtocol ?? self::cryptoProtocol($crypto),
            'encrypted' => $encrypted || $crypto !== [],
            'fingerprint' => self::peerFingerprint($stream),
        ];
    }

    /** @param array<string, mixed> $crypto */
    private static function cryptoProtocol(array $crypto): ?string
    {
        $protocol = $crypto['alpn_protocol'] ?? null;

        return is_string($protocol) && $protocol !== '' ? $protocol : null;
    }

    /** @param resource $stream */
    private static function peerFingerprint(mixed $stream): ?string
    {
        if (!function_exists('openssl_x509_fingerprint')) {
            return null;
        }

        $options = stream_context_get_options($stream);
        $ssl = $options['ssl'] ?? null;
        $certificate = is_array($ssl) ? ($ssl['peer_certificate'] ?? null) : null;
        if (!$certificate instanceof OpenSSLCertificate) {
            return null;
        }

        $fingerprint = openssl_x509_fingerprint($certificate, 'sha256');

        return is_string($fingerprint) ? $fingerprint : null;
    }

    /** @param resource $stream */
    private static function socketAddress(mixed $stream, bool $remote): ?string
    {
        set_error_handler(static fn(int $severity): bool => $severity === E_WARNING);

        try {
            $address = stream_socket_get_name($stream, $remote);
        } finally {
            restore_error_handler();
        }

        return is_string($address) && $address !== '' ? $address : null;
    }
}
