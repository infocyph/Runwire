<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Tests\Support;

use OpenSSLAsymmetricKey;
use OpenSSLCertificate;
use OpenSSLCertificateSigningRequest;
use RuntimeException;

/**
 * Create one isolated RSA identity without sharing key material between fixtures.
 *
 * @param array<string,string> $requestOptions
 * @param array<string,string> $certificateOptions
 * @return array{OpenSSLCertificate,OpenSSLAsymmetricKey}
 * @throws RuntimeException
 */
function signingPair(string $commonName, array $requestOptions, array $certificateOptions): array
{
    $key = openssl_pkey_new(['private_key_bits' => 2_048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    if (!$key instanceof OpenSSLAsymmetricKey) {
        throw new RuntimeException('Unable to generate the isolated test signing key.');
    }
    $request = openssl_csr_new(['commonName' => $commonName], $key, $requestOptions);
    if (!$request instanceof OpenSSLCertificateSigningRequest || !$key instanceof OpenSSLAsymmetricKey) {
        throw new RuntimeException('Unable to generate the isolated test certificate request.');
    }
    $certificate = openssl_csr_sign($request, null, $key, 1, $certificateOptions);
    if (!$certificate instanceof OpenSSLCertificate) {
        throw new RuntimeException('Unable to self-sign the isolated test certificate.');
    }

    return [$certificate, $key];
}

/** @param array<string,mixed> $metadata */
function negotiatedAlpn(array $metadata): ?string
{
    $crypto = $metadata['crypto'] ?? null;
    if (!is_array($crypto)) {
        return null;
    }
    $protocol = $crypto['alpn_protocol'] ?? null;

    return is_string($protocol) ? $protocol : null;
}
