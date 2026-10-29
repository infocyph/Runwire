<?php

declare(strict_types=1);

use Infocyph\Runwire\Network\DialEndpoint;
use Infocyph\Runwire\Network\PoolIsolationKey;
use Infocyph\Runwire\Network\TlsClientOptions;

it('canonicalizes only DNS host casing while keeping endpoint and scope boundaries distinct', function (): void {
    $one = new PoolIsolationKey(DialEndpoint::parse('tcp://Example.test:443'), 'tenant-one');
    $same = new PoolIsolationKey(DialEndpoint::parse('tcp://example.test:443'), 'tenant-one');
    $otherPort = new PoolIsolationKey(DialEndpoint::parse('tcp://example.test:8443'), 'tenant-one');
    $otherScope = new PoolIsolationKey(DialEndpoint::parse('tcp://example.test:443'), 'tenant-two');
    $unix = new PoolIsolationKey(DialEndpoint::parse('unix:///tmp/runwire-pool.sock'), 'tenant-one');

    expect($one->equals($same))->toBeTrue()
        ->and($one->id())->toBe($same->id())
        ->and(strlen($one->id()))->toBe(64)
        ->and($one->equals($otherPort))->toBeFalse()
        ->and($one->equals($otherScope))->toBeFalse()
        ->and($one->equals($unix))->toBeFalse();
});

it('separates TLS trust, peer identity, ALPN, proxy and proxy authentication revisions', function (): void {
    $endpoint = DialEndpoint::parse('tcp://example.test:443');
    $tls = new TlsClientOptions('example.test');
    $profile = new PoolIsolationKey($endpoint, 'tenant', $tls, 'trust-r1', 'h2');
    $same = new PoolIsolationKey($endpoint, 'tenant', $tls, 'trust-r1', 'h2');
    $rotated = new PoolIsolationKey($endpoint, 'tenant', $tls, 'trust-r2', 'h2');
    $h1 = new PoolIsolationKey($endpoint, 'tenant', $tls, 'trust-r1', 'http/1.1');
    $otherPeer = new PoolIsolationKey($endpoint, 'tenant', new TlsClientOptions('other.test'), 'trust-r1', 'h2');
    $proxy = DialEndpoint::parse('tcp://proxy.test:3128');
    $proxied = new PoolIsolationKey($endpoint, 'tenant', $tls, 'trust-r1', 'h2', $proxy, 'proxy-auth-a');
    $otherAuth = new PoolIsolationKey($endpoint, 'tenant', $tls, 'trust-r1', 'h2', $proxy, 'proxy-auth-b');

    expect($profile->equals($same))->toBeTrue()
        ->and($profile->equals($rotated))->toBeFalse()
        ->and($profile->equals($h1))->toBeFalse()
        ->and($profile->equals($otherPeer))->toBeFalse()
        ->and($profile->equals($proxied))->toBeFalse()
        ->and($proxied->equals($otherAuth))->toBeFalse();
});

it('rejects missing security and tenant boundaries rather than using unsafe defaults', function (): void {
    $endpoint = DialEndpoint::parse('tcp://example.test:443');
    $tls = new TlsClientOptions('example.test');
    $proxy = DialEndpoint::parse('tcp://proxy.test:3128');

    expect(fn() => new PoolIsolationKey($endpoint, ''))->toThrow(InvalidArgumentException::class)
        ->and(fn() => new PoolIsolationKey($endpoint, "scope\0bad"))->toThrow(InvalidArgumentException::class)
        ->and(fn() => new PoolIsolationKey($endpoint, 'tenant', $tls))->toThrow(InvalidArgumentException::class)
        ->and(fn() => new PoolIsolationKey($endpoint, 'tenant', tlsPolicyIdentity: 'r1'))->toThrow(InvalidArgumentException::class)
        ->and(fn() => new PoolIsolationKey($endpoint, 'tenant', negotiatedAlpn: 'h2'))->toThrow(InvalidArgumentException::class)
        ->and(fn() => new PoolIsolationKey($endpoint, 'tenant', $tls, 'r1', 'h3'))->toThrow(InvalidArgumentException::class)
        ->and(fn() => new PoolIsolationKey($endpoint, 'tenant', proxy: $proxy))->toThrow(InvalidArgumentException::class)
        ->and(fn() => new PoolIsolationKey($endpoint, 'tenant', proxyPolicyIdentity: 'auth'))->toThrow(InvalidArgumentException::class);
});
