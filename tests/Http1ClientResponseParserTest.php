<?php

declare(strict_types=1);

use Infocyph\Runwire\Http\Client\Http1ClientResponseParser;
use Infocyph\Runwire\Http\Headers;

it('decodes fragmented fixed length HTTP/1.1 responses without retaining body bytes', function (): void {
    $parser = new Http1ClientResponseParser();
    expect($parser->status())->toBeNull();
    $head = null;
    $body = [];
    $end = [];
    $readHead = static function (int $status, Headers $headers) use (&$head): void {
        $head = [$status, $headers->first('content-length')];
    };
    $readBody = static function (string $chunk) use (&$body): void {
        $body[] = $chunk;
    };
    $complete = static function (?Headers $trailers) use (&$end): void {
        $end[] = $trailers;
    };
    $parser->push("HTTP/1.1 200 OK\r\nContent-Length: 5\r\n\r\nhe", $readHead, $readBody, $complete);
    expect($parser->done())->toBeFalse();
    $parser->push('llo', $readHead, $readBody, $complete);
    expect($head)->toBe([200, '5'])
        ->and(implode('', $body))->toBe('hello')
        ->and($parser->done())->toBeTrue()
        ->and($parser->status())->toBe(200)
        ->and($parser->keepAlive())->toBeTrue()
        ->and($end)->toBe([null]);
});

it('handles chunked binary responses with split chunk framing and trailers', function (): void {
    $parser = new Http1ClientResponseParser();
    $body = '';
    $trailers = null;
    $head = static function (): void {};
    $data = static function (string $bytes) use (&$body): void {
        $body .= $bytes;
    };
    $end = static function (?Headers $value) use (&$trailers): void {
        $trailers = $value;
    };
    foreach ([
        "HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\n\r\n",
        "3\r\nab\0",
        "\r\n2\r\ncd\r\n0\r\n",
        "x-end: yes\r\n\r\n",
    ] as $fragment) {
        $parser->push($fragment, $head, $data, $end);
    }
    \PHPUnit\Framework\Assert::assertInstanceOf(Headers::class, $trailers);
    expect($body)->toBe("ab\0cd")
        ->and($parser->done())->toBeTrue()
        ->and($trailers->first('x-end'))->toBe('yes');
});

it('rejects response splitting and ambiguous CL/TE framing without exposing a partial body', function (): void {
    $head = static function (): void {};
    $body = static function (): void {};
    $end = static function (): void {};
    $parser = new Http1ClientResponseParser();
    expect(fn() => $parser->push(
        "HTTP/1.1 200 OK\r\nContent-Length: 7\r\nTransfer-Encoding: chunked\r\n\r\n",
        $head, $body, $end,
    ))->toThrow(RuntimeException::class, 'Ambiguous');

    $parser = new Http1ClientResponseParser();
    expect(fn() => $parser->push(
        "HTTP/1.1 200 OK\r\n bad-folded-header: x\r\nContent-Length: 0\r\n\r\n",
        $head, $body, $end,
    ))->toThrow(RuntimeException::class, 'folded');

    $parser = new Http1ClientResponseParser();
    expect(fn() => $parser->push(
        "HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\nINJECTED",
        $head, $body, $end,
    ))->toThrow(RuntimeException::class, 'Unexpected');
});

it('treats close-delimited bodies as nonreusable and rejects premature framed EOF', function (): void {
    $parser = new Http1ClientResponseParser();
    $body = '';
    $head = static function (): void {};
    $onBody = static function (string $chunk) use (&$body): void {
        $body .= $chunk;
    };
    $onEnd = static function (): void {};
    $parser->push("HTTP/1.0 200 OK\r\n\r\npayload", $head, $onBody, $onEnd);
    expect($body)->toBe('payload')
        ->and($parser->done())->toBeFalse();
    $parser->finish($onEnd);
    expect($parser->done())->toBeTrue()
        ->and($parser->keepAlive())->toBeFalse();

    $premature = new Http1ClientResponseParser();
    $premature->push("HTTP/1.1 200 OK\r\nContent-Length: 9\r\n\r\nshort", $head, $onBody, $onEnd);
    expect(fn() => $premature->finish($onEnd))->toThrow(RuntimeException::class, 'closed');
});

it('honors tokenized Connection close and rejects malformed chunk extension controls', function (): void {
    $parser = new Http1ClientResponseParser();
    $parser->push(
        "HTTP/1.1 200 OK\r\nConnection: keep-alive, close\r\nContent-Length: 0\r\n\r\n",
        static function (): void {},
        static function (): void {},
        static function (): void {},
    );
    expect($parser->done())->toBeTrue()
        ->and($parser->keepAlive())->toBeFalse();

    $parser = new Http1ClientResponseParser();
    $parser->push(
        "HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\n\r\n",
        static function (): void {},
        static function (): void {},
        static function (): void {},
    );
    expect(fn() => $parser->push(
        "1;bad\x01extension\r\nA\r\n0\r\n\r\n",
        static function (): void {},
        static function (): void {},
        static function (): void {},
    ))->toThrow(RuntimeException::class, 'chunk extension');
});

it('does not apply the H1 body budget to HEAD or 304 representation metadata', function (bool $head, int $status): void {
    $parser = new Http1ClientResponseParser(headRequest: $head, maxBodyBytes: 1);
    $seen = [];
    $body = '';
    $parser->push(
        "HTTP/1.1 $status Response\r\nContent-Length: 99999999\r\n\r\n",
        static function (int $code, Headers $headers) use (&$seen): void {
            $seen = [$code, $headers->first('content-length')];
        },
        static function (string $bytes) use (&$body): void { $body .= $bytes; },
        static function (): void {},
    );
    expect($seen)->toBe([$status, '99999999'])
        ->and($body)->toBe('')
        ->and($parser->done())->toBeTrue();
})->with([[true, 200], [false, 304]]);

it('rejects H1 205 content before body callbacks and accepts an empty chunked response', function (): void {
    $body = '';
    $head = static function (): void {};
    $data = static function (string $bytes) use (&$body): void { $body .= $bytes; };
    $end = static function (): void {};
    $parser = new Http1ClientResponseParser();
    expect(fn() => $parser->push("HTTP/1.1 205 Reset Content\r\nContent-Length: 1\r\n\r\nx", $head, $data, $end))
        ->toThrow(InvalidArgumentException::class, 'zero Content-Length');
    foreach (["Transfer-Encoding: chunked\r\n\r\n1\r\nx\r\n0\r\n\r\n", "Connection: close\r\n\r\nx"] as $wire) {
        $parser = new Http1ClientResponseParser();
        expect(fn() => $parser->push("HTTP/1.1 205 Reset Content\r\n" . $wire, $head, $data, $end))
            ->toThrow(RuntimeException::class, 'content is forbidden');
    }
    $parser = new Http1ClientResponseParser();
    $parser->push("HTTP/1.1 205 Reset Content\r\nTransfer-Encoding: chunked\r\n\r\n0\r\n\r\n", $head, $data, $end);
    expect($body)->toBe('')->and($parser->done())->toBeTrue();
});
