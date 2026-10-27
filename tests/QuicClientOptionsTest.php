<?php

declare(strict_types=1);

use Infocyph\Runwire\Exception\RuntimeUnavailableException;
use Infocyph\Runwire\Network\QuicClientOptions;

it('builds a fail-closed native QUIC TLS 1.3 policy with explicit ALPN and SNI', function (): void {
    $options = new QuicClientOptions('example.com');
    $context = $options->nativeOptions();
    expect($context['peer_name'])->toBe('example.com')
        ->and($context['alpn'])->toBe(['h3'])
        ->and($context['verify_peer'])->toBeTrue()
        ->and($context['verify_peer_name'])->toBeTrue()
        ->and($context['allow_self_signed'])->toBeFalse()
        ->and($context['SNI_enabled'])->toBeTrue()
        ->and($context['connect_timeout_ms'])->toBe(10_000)
        ->and($options->maxOpenStreams)->toBe(64);
});

it('fails closed when native QUIC lacks datagrams, migration, or 0-RTT', function (): void {
    expect(fn() => (new QuicClientOptions('example.com', allowEarlyData: true))->nativeOptions())
        ->toThrow(RuntimeUnavailableException::class)
        ->and(fn() => (new QuicClientOptions('example.com', allowMigration: true))->nativeOptions())
        ->toThrow(RuntimeUnavailableException::class)
        ->and(fn() => (new QuicClientOptions('example.com', requireDatagrams: true))->nativeOptions())
        ->toThrow(RuntimeUnavailableException::class);
});

it('bounds QUIC stream count, connect deadline, identities, and ALPN inputs', function (): void {
    expect(fn() => new QuicClientOptions(''))->toThrow(InvalidArgumentException::class)
        ->and(fn() => new QuicClientOptions('example.com', alpnProtocols: []))->toThrow(InvalidArgumentException::class)
        ->and(fn() => new QuicClientOptions('example.com', alpnProtocols: ['h3', 'h3']))->toThrow(InvalidArgumentException::class)
        ->and(fn() => new QuicClientOptions('example.com', maxOpenStreams: 0))->toThrow(InvalidArgumentException::class)
        ->and(fn() => new QuicClientOptions('example.com', connectTimeoutMilliseconds: 0))->toThrow(InvalidArgumentException::class)
        ->and(fn() => new QuicClientOptions("invalid\0host"))->toThrow(InvalidArgumentException::class)
        ->and(fn() => new QuicClientOptions('example.com', caFile: '/missing/quic-ca.pem'))
        ->toThrow(InvalidArgumentException::class);
});


it('refuses direct ext-quic constructor use on the Runwire event-loop thread', function (): void {
    $options = new QuicClientOptions('example.com');

    expect($options->nativeCapabilities())->toBe([
        'tls13' => true,
        'bidirectional_streams' => true,
        'unidirectional_streams' => true,
        'datagrams' => false,
        'migration' => false,
        'early_data' => false,
        'nonblocking_client_handshake' => false,
    ])->and(fn() => $options->assertEventLoopSafeNativeClient())
        ->toThrow(RuntimeUnavailableException::class, 'blocks during TLS handshake');
});

it('pins the QUIC path and rejects migration or address rebinding explicitly', function (): void {
    $policy = new QuicClientOptions('example.com');
    $policy->assertStablePath('192.0.2.1:443', '192.0.2.1:443');

    expect(fn() => $policy->assertStablePath('192.0.2.1:443', '192.0.2.2:443'))
        ->toThrow(RuntimeUnavailableException::class, 'migration/rebinding')
        ->and(fn() => $policy->assertStablePath('192.0.2.1:443', '192.0.2.1:8443'))
        ->toThrow(RuntimeUnavailableException::class, 'migration/rebinding')
        ->and(fn() => $policy->assertStablePath('', '192.0.2.1:443'))
        ->toThrow(RuntimeUnavailableException::class, 'migration/rebinding');
});

it('refuses unsupported QUIC datagrams and early application dispatch before confirmation', function (): void {
    $policy = new QuicClientOptions('example.com');
    $policy->assertApplicationDispatchAllowed(true);

    expect(fn() => $policy->assertDatagramAvailable())
        ->toThrow(RuntimeUnavailableException::class, 'cannot send or receive')
        ->and(fn() => $policy->assertApplicationDispatchAllowed(false))
        ->toThrow(RuntimeUnavailableException::class, '0-RTT application dispatch');
});
