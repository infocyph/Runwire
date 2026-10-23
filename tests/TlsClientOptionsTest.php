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
