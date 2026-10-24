<?php

declare(strict_types=1);

use Infocyph\Runwire\Exception\RuntimeUnavailableException;
use Infocyph\Runwire\Http\Client\ClientBodySourceInterface;
use Infocyph\Runwire\Http\Client\ClientExchangeInterface;
use Infocyph\Runwire\Http\Client\ClientProtocolPolicy;
use Infocyph\Runwire\Http\Client\ClientRequest;
use Infocyph\Runwire\Http\Client\ClientResponseInterface;
use Infocyph\Runwire\Http\Enum\ProtocolVersion;
use Infocyph\Runwire\Http\Headers;

it('requires explicit secure HTTP version negotiation without implicit fallback', function (): void {
    $strict = new ClientProtocolPolicy();
    expect($strict->negotiate('h2', true))->toBe(ProtocolVersion::HTTP_2)
        ->and(fn() => $strict->negotiate('http/1.1', true))->toThrow(RuntimeUnavailableException::class)
        ->and(fn() => $strict->negotiate(null, true))->toThrow(RuntimeUnavailableException::class)
        ->and(fn() => $strict->negotiate(null, false))->toThrow(RuntimeUnavailableException::class)
        ->and(fn() => $strict->negotiate('h3', true))->toThrow(RuntimeUnavailableException::class);

    $fallback = new ClientProtocolPolicy(allowFallback: true);
    expect($fallback->negotiate('http/1.1', true))->toBe(ProtocolVersion::HTTP_1_1)
        ->and($fallback->negotiate(null, true))->toBe(ProtocolVersion::HTTP_1_1)
        ->and($fallback->negotiate('h2', true))->toBe(ProtocolVersion::HTTP_2);
});

it('allows HTTP/3 only on encrypted QUIC with approved ALPN', function (): void {
    $h3 = new ClientProtocolPolicy(
        preferred: ProtocolVersion::HTTP_3,
        allowedVersions: [ProtocolVersion::HTTP_3],
    );
    expect($h3->negotiate('h3', true, true))->toBe(ProtocolVersion::HTTP_3)
        ->and(fn() => $h3->negotiate('h3', true, false))->toThrow(RuntimeUnavailableException::class)
        ->and(fn() => $h3->negotiate('h2', true, true))->toThrow(RuntimeUnavailableException::class)
        ->and(fn() => $h3->negotiate('h3', false, true))->toThrow(RuntimeUnavailableException::class);
});

it('requires explicit plaintext H1 opt-in and rejects duplicate version policies', function (): void {
    $plain = new ClientProtocolPolicy(
        preferred: ProtocolVersion::HTTP_1_1,
        allowedVersions: [ProtocolVersion::HTTP_1_1],
        allowPlaintext: true,
    );
    expect($plain->negotiate(null, false))->toBe(ProtocolVersion::HTTP_1_1)
        ->and(fn() => new ClientProtocolPolicy(allowedVersions: [ProtocolVersion::HTTP_2, ProtocolVersion::HTTP_2]))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn() => new ClientProtocolPolicy(allowedVersions: [ProtocolVersion::HTTP_3]))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn() => new ClientProtocolPolicy(allowedVersions: []))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects unsafe outbound request methods, userinfo and unintended HTTP origins', function (): void {
    expect(fn() => new ClientRequest('GET', 'http://example.test/'))->toThrow(InvalidArgumentException::class)
        ->and(fn() => new ClientRequest("GET\r\nInjected", 'https://example.test/'))->toThrow(InvalidArgumentException::class)
        ->and(fn() => new ClientRequest('GET', 'https://person@example.test/'))->toThrow(InvalidArgumentException::class)
        ->and(fn() => new ClientRequest('GET', 'https://person:secret@example.test/'))->toThrow(InvalidArgumentException::class)
        ->and(fn() => new ClientRequest('GET', 'https://example.test/path#fragment'))->toThrow(InvalidArgumentException::class)
        ->and(fn() => new ClientRequest('GET', '/relative/path'))->toThrow(InvalidArgumentException::class);

    $plain = new ClientProtocolPolicy(
        preferred: ProtocolVersion::HTTP_1_1,
        allowedVersions: [ProtocolVersion::HTTP_1_1],
        allowPlaintext: true,
    );
    expect((new ClientRequest('GET', 'http://example.test/', protocol: $plain))->uri)->toBe('http://example.test/');
});

it('preserves bounded streaming request body and response contract without a transport', function (): void {
    $body = new class implements ClientBodySourceInterface {
        private string $buffer = 'payload';

        public function read(int $maxBytes = PHP_INT_MAX): string
        {
            $chunk = substr($this->buffer, 0, $maxBytes);
            $this->buffer = substr($this->buffer, strlen($chunk));

            return $chunk;
        }

        public function eof(): bool
        {
            return $this->buffer === '';
        }

        public function trailers(): ?Headers
        {
            return null;
        }
    };
    $request = new ClientRequest(
        'POST',
        'https://example.test/upload',
        Headers::fromArray(['content-type' => 'text/plain']),
        $body,
    );
    expect($request->headers->first('content-type'))->toBe('text/plain')
        ->and($request->body?->read(3))->toBe('pay')
        ->and($request->body?->eof())->toBeFalse()
        ->and($request->body?->read(16))->toBe('load')
        ->and($request->body?->eof())->toBeTrue()
        ->and(interface_exists(ClientResponseInterface::class))->toBeTrue()
        ->and(interface_exists(ClientExchangeInterface::class))->toBeTrue();
});
