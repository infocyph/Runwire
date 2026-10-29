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
    $request = PsrMessageBridge::outboundRequest($psr);
    expect($request->uri)->toBe('https://example.com/path')
        ->and($request->headers->all('accept'))->toBe(['a', 'b']);
    $restored = PsrMessageBridge::requestMetadata($request, new Psr17Factory());
    expect($restored->getMethod())->toBe('GET')
        ->and($restored->getHeader('accept'))->toBe(['a', 'b']);
});

it('requires explicit plaintext policy and rejects blocking PSR bodies', function (): void {
    $request = new Request('POST', 'https://example.com/path', [], 'nonempty');
    expect(fn() => PsrMessageBridge::outboundRequest($request))->toThrow(RuntimeUnavailableException::class);
    expect(fn() => PsrMessageBridge::outboundRequest(new Request('GET', 'http://example.com/')))
        ->toThrow(InvalidArgumentException::class);

    $policy = new ClientProtocolPolicy(
        ProtocolVersion::HTTP_1_1, [ProtocolVersion::HTTP_1_1], allowPlaintext: true,
    );
    expect(PsrMessageBridge::outboundRequest(new Request('GET', 'http://example.com/'), $policy)->uri)
        ->toBe('http://example.com/');
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
