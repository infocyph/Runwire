<?php

declare(strict_types=1);

use Infocyph\Runwire\Http\Client\ClientBodySourceInterface;
use Infocyph\Runwire\Http\Client\ClientProtocolPolicy;
use Infocyph\Runwire\Http\Client\ClientRequest;
use Infocyph\Runwire\Http\Client\Http1ClientRequestWriter;
use Infocyph\Runwire\Http\Enum\ProtocolVersion;
use Infocyph\Runwire\Http\Headers;

function runwireHttp1ClientPolicy(): ClientProtocolPolicy
{
    return new ClientProtocolPolicy(
        preferred: ProtocolVersion::HTTP_1_1,
        allowedVersions: [ProtocolVersion::HTTP_1_1],
        allowPlaintext: true,
    );
}

it('emits a bounded HTTP/1.1 request line and canonical authority without a body', function (): void {
    $request = new ClientRequest('GET', 'http://example.test:8080/path?a=1', protocol: runwireHttp1ClientPolicy());
    $writer = new Http1ClientRequestWriter($request);
    expect($writer->next())->toBe("GET /path?a=1 HTTP/1.1\r\nHost: example.test:8080\r\n\r\n")
        ->and($writer->done())->toBeTrue()
        ->and($writer->next())->toBe('');
});

it('streams chunked binary uploads and validates final trailers', function (): void {
    $source = new class implements ClientBodySourceInterface {
        private string $remaining = "ab\0cd";

        public function eof(): bool
        {
            return $this->remaining === '';
        }

        public function read(int $maxBytes = PHP_INT_MAX): string
        {
            $result = substr($this->remaining, 0, $maxBytes);
            $this->remaining = substr($this->remaining, strlen($result));

            return $result;
        }

        public function trailers(): ?Headers
        {
            return Headers::fromArray(['x-end' => 'yes']);
        }
    };
    $request = new ClientRequest('POST', 'http://example.test/upload', body: $source, protocol: runwireHttp1ClientPolicy());
    $writer = new Http1ClientRequestWriter($request);
    expect($writer->next())->toContain("Transfer-Encoding: chunked\r\n\r\n")
        ->and($writer->next(3))->toBe("3\r\nab\0\r\n")
        ->and($writer->next(3))->toBe("2\r\ncd\r\n")
        ->and($writer->done())->toBeFalse()
        ->and($writer->next())->toBe("0\r\nx-end: yes\r\n\r\n")
        ->and($writer->done())->toBeTrue();
});

it('rejects unauthorized HTTP downgrade, mismatched Host and ambiguous request framing', function (): void {
    expect(fn() => new Http1ClientRequestWriter(new ClientRequest('GET', 'https://example.test/')))
        ->toThrow(InvalidArgumentException::class, 'explicit protocol preference')
        ->and(fn() => new Http1ClientRequestWriter(new ClientRequest(
            'GET',
            'http://example.test/',
            Headers::fromArray(['host' => 'attacker.test']),
            protocol: runwireHttp1ClientPolicy(),
        )))->toThrow(InvalidArgumentException::class, 'Host header')
        ->and(fn() => new Http1ClientRequestWriter(new ClientRequest(
            'GET',
            'http://example.test/',
            Headers::fromArray(['transfer-encoding' => 'chunked']),
            protocol: runwireHttp1ClientPolicy(),
        )))->toThrow(InvalidArgumentException::class, 'framing');
});
