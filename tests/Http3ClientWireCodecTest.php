<?php

declare(strict_types=1);

use Infocyph\Runwire\Exception\RuntimeUnavailableException;
use Infocyph\Runwire\Http\Client\ClientProtocolPolicy;
use Infocyph\Runwire\Http\Client\ClientRequest;
use Infocyph\Runwire\Http\Client\Internal\Http3ClientWireCodec;
use Infocyph\Runwire\Http\Headers;
use Infocyph\Runwire\Http\Enum\ProtocolVersion;
use Infocyph\Runwire\Http\Http3\Enum\FrameType;
use Infocyph\Runwire\Http\Http3\FrameParser;
use Infocyph\Runwire\Http\Http3\Http3Exception;
use Infocyph\Runwire\Http\Http3\Qpack\Decoder;
use Infocyph\Runwire\Http\Http3\Qpack\Encoder;

function h3TestPolicy(): ClientProtocolPolicy
{
    return new ClientProtocolPolicy(
        preferred: ProtocolVersion::HTTP_3,
        allowedVersions: [ProtocolVersion::HTTP_3],
    );
}

it('builds QPACK-unblocked HTTPS GET with exact origin pseudo-headers', function (): void {
    $codec = new Http3ClientWireCodec();
    $request = new ClientRequest('GET', 'https://example.com:8443/search?q=one',
        Headers::fromArray(['accept' => 'application/json']), protocol: h3TestPolicy());
    $frames = (new FrameParser())->push($codec->encodeRequestHeaders($request, 1));
    expect($frames)->toHaveCount(1)
        ->and($frames[0]->type)->toBe(FrameType::HEADERS->value);
    $decoded = (new Decoder(0, 0))->decode($frames[0]->payload, 1);
    expect($decoded)->not->toBeNull()
        ->and($decoded->requiredInsertCount)->toBe(0)
        ->and($decoded->fields)->toContain(
            [':method', 'GET'],
            [':scheme', 'https'],
            [':authority', 'example.com:8443'],
            [':path', '/search?q=one'],
            ['accept', 'application/json'],
        );
});

it('decodes bounded static QPACK responses with validated status and headers', function (): void {
    $codec = new Http3ClientWireCodec();
    $encoder = new Encoder(0, 0);
    $block = $encoder->encode([
        [':status', '200'],
        ['content-type', 'application/json'],
    ], 1)->block;
    $result = $codec->decodeResponseHeaders($block, 1);
    expect($result['status'])->toBe(200)
        ->and($result['headers']->first('content-type'))->toBe('application/json');
});

it('rejects malformed pseudo-header order and missing response status', function (): void {
    $codec = new Http3ClientWireCodec();
    $encoder = new Encoder(0, 0);
    $wrong = $encoder->encode([['content-type', 'text/plain'], [':status', '200']], 1)->block;
    $missing = $encoder->encode([['content-type', 'text/plain']], 2)->block;
    expect(fn() => $codec->decodeResponseHeaders($wrong, 1))->toThrow(Http3Exception::class, 'pseudo-header')
        ->and(fn() => $codec->decodeResponseHeaders($missing, 2))->toThrow(Http3Exception::class, 'no valid :status');
});

it('fails closed on HTTP3 downgrade and unsafe request headers before writing', function (): void {
    $codec = new Http3ClientWireCodec();
    $h2 = new ClientRequest('GET', 'https://example.com/');
    $hop = new ClientRequest('GET', 'https://example.com/',
        Headers::fromArray(['Connection' => 'keep-alive']), protocol: h3TestPolicy());
    $forgedHost = new ClientRequest('GET', 'https://example.com/',
        Headers::fromArray(['Host' => 'other.example']), protocol: h3TestPolicy());

    expect(fn() => $codec->encodeRequestHeaders($h2, 1))->toThrow(RuntimeUnavailableException::class)
        ->and(fn() => $codec->encodeRequestHeaders($hop, 1))->toThrow(InvalidArgumentException::class, 'prohibited')
        ->and(fn() => $codec->encodeRequestHeaders($forgedHost, 1))->toThrow(InvalidArgumentException::class, 'prohibited');
});

it('enforces field-section size without retaining invalid outstanding dynamic references', function (): void {
    $codec = new Http3ClientWireCodec(160);
    $request = new ClientRequest('GET', 'https://example.com/',
        Headers::fromArray(['x-large-field' => str_repeat('a', 128)]), protocol: h3TestPolicy());
    expect(fn() => $codec->encodeRequestHeaders($request, 1))->toThrow(InvalidArgumentException::class, 'configured limit');
});

it('refuses CONNECT pseudo-header misuse and hop-by-hop response metadata', function (): void {
    $codec = new Http3ClientWireCodec();
    $request = new ClientRequest('CONNECT', 'https://example.com:443/', protocol: h3TestPolicy());
    $encoder = new Encoder(0, 0);
    $response = $encoder->encode([[':status', '200'], ['connection', 'keep-alive']], 7)->block;

    expect(fn() => $codec->encodeRequestHeaders($request, 7))
        ->toThrow(RuntimeUnavailableException::class, 'CONNECT needs')
        ->and(fn() => $codec->decodeResponseHeaders($response, 7))
        ->toThrow(Http3Exception::class, 'hop-by-hop');
});


it('permits bounded H3 upload headers but rejects ambiguous length and proxy credentials', function (): void {
    $body = new class implements \Infocyph\Runwire\Http\Client\ClientBodySourceInterface {
        public function eof(): bool { return true; }
        public function read(int $maxBytes = PHP_INT_MAX): string { return $maxBytes < 1 ? '' : ''; }
        public function trailers(): ?Headers { return null; }
    };
    $codec = new Http3ClientWireCodec();
    $upload = new ClientRequest('POST', 'https://example.com/upload', body: $body, protocol: h3TestPolicy());
    $frames = (new FrameParser())->push($codec->encodeRequestHeaders($upload, 1));
    expect($frames)->toHaveCount(1)->and($frames[0]->type)->toBe(FrameType::HEADERS->value);

    $wrongLength = new ClientRequest('POST', 'https://example.com/upload',
        Headers::fromArray(['content-length' => '3']), body: $body, protocol: h3TestPolicy());
    $proxySecret = new ClientRequest('POST', 'https://example.com/upload',
        Headers::fromArray(['proxy-authorization' => 'Basic secret']), body: $body, protocol: h3TestPolicy());
    expect(fn() => $codec->encodeRequestHeaders($wrongLength, 3))
        ->toThrow(InvalidArgumentException::class, 'prohibited')
        ->and(fn() => $codec->encodeRequestHeaders($proxySecret, 3))
        ->toThrow(InvalidArgumentException::class, 'prohibited');
});

it('encodes static QPACK trailers but rejects framing and hop-by-hop fields', function (): void {
    $codec = new Http3ClientWireCodec();
    $bytes = $codec->encodeRequestTrailers(Headers::fromArray(['x-upload-finished' => 'yes']), 3);
    $frames = (new FrameParser())->push($bytes);
    expect($frames)->toHaveCount(1)->and($frames[0]->type)->toBe(FrameType::HEADERS->value);
    $fields = (new Decoder(0, 0))->decode($frames[0]->payload, 3);
    expect($fields)->not->toBeNull()
        ->and($fields->fields)->toContain(['x-upload-finished', 'yes']);
    expect(fn() => $codec->encodeRequestTrailers(Headers::fromArray(['content-length' => '42']), 3))
        ->toThrow(InvalidArgumentException::class, 'prohibited')
        ->and(fn() => $codec->encodeRequestTrailers(Headers::fromArray(['connection' => 'close']), 3))
        ->toThrow(InvalidArgumentException::class, 'prohibited');
});
