<?php

declare(strict_types=1);

use Infocyph\Runwire\Network\TlsClientOptions;

it('enforces peer chain and hostname verification for every client TLS context', function (): void {
    $policy = new TlsClientOptions('example.com');
    $options = $policy->context();
    expect($options['verify_peer'])->toBeTrue()
        ->and($options['verify_peer_name'])->toBeTrue()
        ->and($options['peer_name'])->toBe('example.com')
        ->and($options['SNI_enabled'])->toBeTrue()
        ->and($options['SNI_server_name'])->toBe('example.com')
        ->and($options['allow_self_signed'])->toBeFalse()
        ->and($options['capture_peer_cert'])->toBeTrue()
        ->and($options['disable_compression'])->toBeTrue()
        ->and($options['verify_depth'])->toBe(8)
        ->and($options['alpn_protocols'])->toBe('h2,http/1.1')
        ->and($options['crypto_method'])->toBe($policy->method());
});

it('preserves explicit CA roots and independent ALPN policies without disabling verification', function (): void {
    $caFile = tempnam(sys_get_temp_dir(), 'runwire-client-ca-');
    expect($caFile)->toBeString();
    try {
        $policy = new TlsClientOptions('localhost', $caFile, null, ['http/1.1']);
        $context = $policy->context();
        expect($context['cafile'])->toBe($caFile)
            ->and($context['alpn_protocols'])->toBe('http/1.1')
            ->and($context['verify_peer'])->toBeTrue()
            ->and($context['verify_peer_name'])->toBeTrue();
    } finally {
        unlink($caFile);
    }
});

it('rejects empty identities, invalid trust roots and unsafe TLS timeout/ALPN settings', function (): void {
    expect(fn() => new TlsClientOptions(''))->toThrow(InvalidArgumentException::class)
        ->and(fn() => new TlsClientOptions("bad\0name"))->toThrow(InvalidArgumentException::class)
        ->and(fn() => new TlsClientOptions('two words'))->toThrow(InvalidArgumentException::class)
        ->and(fn() => new TlsClientOptions('example.com', caFile: '/missing/runwire-ca.pem'))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn() => new TlsClientOptions('example.com', caPath: '/missing/runwire-ca'))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn() => new TlsClientOptions('example.com', handshakeTimeoutSeconds: INF))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn() => new TlsClientOptions('example.com', alpnProtocols: ['h2', 'h2']))
        ->toThrow(InvalidArgumentException::class);
});


it('configures optional outbound client certificate and private-key identity without weakening peer verification', function (): void {
    $certificate = tempnam(sys_get_temp_dir(), 'runwire-mtls-cert-');
    $key = tempnam(sys_get_temp_dir(), 'runwire-mtls-key-');
    expect($certificate)->toBeString()->and($key)->toBeString();
    try {
        $policy = new TlsClientOptions(
            'localhost',
            clientCertificate: $certificate,
            clientPrivateKey: $key,
            clientPassphrase: 'test-secret',
        );
        $options = $policy->context();
        expect($options['local_cert'])->toBe($certificate)
            ->and($options['local_pk'])->toBe($key)
            ->and($options['passphrase'])->toBe('test-secret')
            ->and($options['verify_peer'])->toBeTrue()
            ->and($options['verify_peer_name'])->toBeTrue()
            ->and($options['verify_depth'])->toBe(8);
    } finally {
        unlink($certificate);
        unlink($key);
    }
});

it('rejects incomplete outbound mutual TLS identities before creating SSL contexts', function (): void {
    expect(fn() => new TlsClientOptions('localhost', clientPrivateKey: '/missing/key.pem'))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn() => new TlsClientOptions('localhost', clientPassphrase: 'secret'))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn() => new TlsClientOptions('localhost', clientCertificate: '/missing/cert.pem'))
        ->toThrow(InvalidArgumentException::class);
});


it('requires an explicit CA and bounded verification for mandatory client certificates', function (): void {
    expect(fn() => new \Infocyph\Runwire\Network\TlsOptions(
        localCertificate: __FILE__,
        requireClientCertificate: true,
    ))->toThrow(InvalidArgumentException::class);
    $policy = new \Infocyph\Runwire\Network\TlsOptions(
        localCertificate: __FILE__,
        extraContext: [
            'cafile' => __FILE__,
            'verify_peer' => false,
            'allow_self_signed' => true,
            'verify_depth' => 128,
        ],
        requireClientCertificate: true,
    );
    $context = $policy->context();
    expect($context['verify_peer'])->toBeTrue()
        ->and($context['allow_self_signed'])->toBeFalse()
        ->and($context['capture_peer_cert'])->toBeTrue()
        ->and($context['verify_depth'])->toBe(8);
});
