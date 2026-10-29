<?php

declare(strict_types=1);

use Infocyph\Runwire\Network\DialEndpoint;
use Infocyph\Runwire\Network\DialOptions;

it('parses IPv4, bracketed IPv6, hostname and Unix endpoint contracts without I/O', function (): void {
    $ipv4 = DialEndpoint::parse('tcp://127.0.0.1:12345');
    expect($ipv4->scheme)->toBe('tcp')
        ->and($ipv4->host)->toBe('127.0.0.1')
        ->and($ipv4->port)->toBe(12345)
        ->and($ipv4->isIpLiteral)->toBeTrue();
    $ipv6 = DialEndpoint::parse('tcp://[::1]:443');
    expect($ipv6->host)->toBe('::1')->and($ipv6->isIpLiteral)->toBeTrue();
    $dns = DialEndpoint::parse('tcp://example.com:443');
    expect($dns->host)->toBe('example.com')->and($dns->isIpLiteral)->toBeFalse();
    $unix = DialEndpoint::parse('unix:///tmp/runwire-dial.sock');
    expect($unix->scheme)->toBe('unix')
        ->and($unix->path)->toBe('/tmp/runwire-dial.sock')
        ->and($unix->isIpLiteral)->toBeFalse();
});

it('rejects invalid endpoint forms and unbounded timeout values', function (): void {
    foreach ([
        '', 'http://127.0.0.1:80', 'tcp://127.0.0.1', 'tcp://127.0.0.1:0',
        'tcp://127.0.0.1:65536', 'tcp://user:pass@127.0.0.1:80',
        'tcp://127.0.0.1:80/path', 'tcp://[invalid]:80', 'unix://relative',
        'unix:///tmp/foo?bar', 'unix://' . str_repeat('a', 120),
        "tcp://localhost:80\0x",
    ] as $bad) {
        expect(fn() => DialEndpoint::parse($bad))->toThrow(InvalidArgumentException::class);
    }
    expect(fn() => new DialOptions(connectTimeoutSeconds: 0))->toThrow(InvalidArgumentException::class)
        ->and(fn() => new DialOptions(connectTimeoutSeconds: INF))->toThrow(InvalidArgumentException::class);
});
