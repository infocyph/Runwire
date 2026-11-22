<?php

declare(strict_types=1);

use Infocyph\Runwire\Exception\RuntimeUnavailableException;
use Infocyph\Runwire\Http\Client\ClientProtocolPolicy;
use Infocyph\Runwire\Http\Client\ClientRequest;
use Infocyph\Runwire\Http\Enum\ProtocolVersion;
use Infocyph\Runwire\Http\Headers;
use Infocyph\Runwire\Interop\Psr\PsrMessageBridge;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Request;

it('adapts bodyless PSR-7 requests to Runwire without blocking the reactor', function (): void {
    $psr = new Request('GET', 'https://example.com/path', ['accept' => ['a', 'b']]);
    $request = PsrMessageBridge::outboundRequest($psr, approvedUri: 'https://example.com/path');
    expect($request->uri)->toBe('https://example.com/path')
        ->and($request->headers->all('accept'))->toBe(['a', 'b']);
    $restored = PsrMessageBridge::requestMetadata($request, new Psr17Factory());
    expect($restored->getMethod())->toBe('GET')
        ->and($restored->getHeader('accept'))->toBe(['a', 'b']);
});

it('requires explicit plaintext policy and rejects blocking PSR bodies', function (): void {
    $request = new Request('POST', 'https://example.com/path', [], 'nonempty');
    expect(fn() => PsrMessageBridge::outboundRequest($request))->toThrow(RuntimeUnavailableException::class);
    expect(fn() => PsrMessageBridge::outboundRequest(new Request('GET', 'http://example.com/'), approvedUri: 'http://example.com/'))
        ->toThrow(InvalidArgumentException::class);

    $policy = new ClientProtocolPolicy(
        ProtocolVersion::HTTP_1_1, [ProtocolVersion::HTTP_1_1], allowPlaintext: true,
    );
    expect(PsrMessageBridge::outboundRequest(new Request('GET', 'http://example.com/'), $policy, approvedUri: 'http://example.com/')->uri)
        ->toBe('http://example.com/');
});

it('rejects unapproved PSR targets before they enter any outbound client', function (): void {
    $source = new Request('GET', 'https://example.com/path?token=a');
    expect(fn() => PsrMessageBridge::outboundRequest($source))->toThrow(InvalidArgumentException::class);

    foreach ([
        'https://attacker.example/path?token=a',
        'https://example.com:8443/path?token=a',
        'http://example.com/path?token=a',
        'https://example.com/other?token=a',
        'https://example.com/path?token=b',
        'https://example.com/path?token=a#fragment',
        'https://example.com@127.0.0.1/path?token=a',
    ] as $approved) {
        expect(fn() => PsrMessageBridge::outboundRequest($source, approvedUri: $approved))
            ->toThrow(InvalidArgumentException::class);
    }

    $authorized = PsrMessageBridge::outboundRequest(
        $source,
        approvedUri: 'https://example.com/path?token=a',
    );
    expect($authorized->uri)->toBe('https://example.com/path?token=a');
});

it('rejects private-network target substitution when caller approved a public URI', function (): void {
    $private = new Request('GET', 'https://127.0.0.1/admin');
    expect(fn() => PsrMessageBridge::outboundRequest(
        $private,
        approvedUri: 'https://api.example.com/admin',
    ))->toThrow(InvalidArgumentException::class);

    expect(fn() => PsrMessageBridge::outboundRequest(
        new Request('GET', 'https://api.example.com@127.0.0.1/admin'),
        approvedUri: 'https://api.example.com/admin',
    ))->toThrow(InvalidArgumentException::class);
});

it('produces PSR-17 response metadata without consuming or altering message bodies', function (): void {
    $response = PsrMessageBridge::responseMetadata(202, Headers::fromArray([
        'x-test' => ['one', 'two'],
    ]), new Psr17Factory());
    expect($response->getStatusCode())->toBe(202)
        ->and($response->getHeader('x-test'))->toBe(['one', 'two'])
        ->and(PsrMessageBridge::headers($response)->all('x-test'))->toBe(['one', 'two']);
});

it('rejects oversized header collections before creating Runwire headers', function (): void {
    $header = new Request('GET', 'https://example.com/', ['x-large' => str_repeat('a', 65536)]);
    expect(fn() => PsrMessageBridge::headers($header))->toThrow(InvalidArgumentException::class);
});
