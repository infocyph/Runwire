<?php

declare(strict_types=1);

use Infocyph\Runwire\Http\Client\Internal\Http2ClientResponseParser;
use Infocyph\Runwire\Http\Headers;
use Infocyph\Runwire\Http\Http2\Enum\FrameType;
use Infocyph\Runwire\Http\Http2\Frame;
use Infocyph\Runwire\Http\Http2\Hpack\Encoder;

it('delivers bounded H2 headers only after CONTINUATION then streams DATA until END_STREAM', function (): void {
    $parser = new Http2ClientResponseParser();
    $block = (new Encoder())->encode([[':status', '200'], ['content-type', 'text/plain']]);
    $seen = [];
    $body = '';
    $header = static function (int $status, Headers $headers) use (&$seen): void {
        $seen[] = [$status, $headers->first('content-type')];
    };
    $chunk = static function (string $bytes) use (&$body): void {
        $body .= $bytes;
    };

    $parser->push(new Frame(FrameType::HEADERS->value, 0, 1, substr($block, 0, 1)), $header, $chunk);
    expect($seen)->toBe([]);
    $parser->push(new Frame(FrameType::CONTINUATION->value, 0x4, 1, substr($block, 1)), $header, $chunk);
    expect($seen)->toBe([[200, 'text/plain']]);
    $parser->push(new Frame(FrameType::DATA->value, 0, 1, 'he'), $header, $chunk);
    $parser->push(new Frame(FrameType::DATA->value, 0x1, 1, 'llo'), $header, $chunk);
    expect($body)->toBe('hello')
        ->and($parser->done())->toBeTrue();
    expect(fn() => $parser->push(new Frame(FrameType::DATA->value, 0x1, 1), $header, $chunk))
        ->toThrow(RuntimeException::class, 'terminal');
});

it('accepts an empty initial HEADERS fragment but never allows an interleaved stream frame', function (): void {
    $parser = new Http2ClientResponseParser();
    $header = static function (): void {};
    $body = static function (): void {};
    $parser->push(new Frame(FrameType::HEADERS->value, 0, 1), $header, $body);
    expect(fn() => $parser->push(new Frame(FrameType::DATA->value, 0, 1, 'x'), $header, $body))
        ->toThrow(RuntimeException::class, 'interleaved');
    $parser->push(
        new Frame(FrameType::CONTINUATION->value, 0x4, 1, (new Encoder())->encode([[':status', '204']])),
        $header,
        $body,
    );
    expect($parser->done())->toBeFalse();
    $parser->push(new Frame(FrameType::DATA->value, 0x1, 1), $header, $body);
    expect($parser->done())->toBeTrue();
});

it('rejects malformed statuses, pseudo ordering, framing and stream-local body overflow without leaking callbacks', function (): void {
    $failures = [
        [['content-type', 'text/plain'], [':status', '200']],
        [[':status', '200'], [':status', '204']],
        [[':status', '103']],
        [[':status', '200'], ['connection', 'close']],
    ];
    foreach ($failures as $headers) {
        $parser = new Http2ClientResponseParser();
        $called = 0;
        expect(fn() => $parser->push(
            new Frame(FrameType::HEADERS->value, 0x5, 1, (new Encoder())->encode($headers)),
            static function () use (&$called): void { ++$called; },
            static function (): void {},
        ))->toThrow(RuntimeException::class);
        expect($called)->toBe(0);
    }

    $parser = new Http2ClientResponseParser(maxHeaderBlockBytes: 128, maxBodyBytes: 4);
    $headers = static function (): void {};
    $body = '';
    $onBody = static function (string $bytes) use (&$body): void { $body .= $bytes; };
    expect(fn() => $parser->push(new Frame(FrameType::DATA->value, 0, 1, 'a'), $headers, $onBody))
        ->toThrow(RuntimeException::class, 'complete headers');
    expect(fn() => $parser->push(new Frame(FrameType::HEADERS->value, 0x5, 3), $headers, $onBody))
        ->toThrow(RuntimeException::class, 'foreign');
    $parser->push(new Frame(FrameType::HEADERS->value, 0x4, 1, (new Encoder())->encode([[':status', '200']])), $headers, $onBody);
    $parser->push(new Frame(FrameType::DATA->value, 0, 1, 'abcd'), $headers, $onBody);
    expect($body)->toBe('abcd');
    expect(fn() => $parser->push(new Frame(FrameType::DATA->value, 0x1, 1, 'e'), $headers, $onBody))
        ->toThrow(RuntimeException::class, 'memory limit');
    expect($body)->toBe('abcd');
});

it('rejects oversized header fragments before allocation and keeps a separate H2 response intact', function (): void {
    $hostile = new Http2ClientResponseParser(maxHeaderBlockBytes: 80);
    expect(fn() => $hostile->push(
        new Frame(FrameType::HEADERS->value, 0x4, 1, str_repeat('a', 81)),
        static function (): void {},
        static function (): void {},
    ))->toThrow(RuntimeException::class, 'memory limit');

    $healthy = new Http2ClientResponseParser();
    $status = 0;
    $healthy->push(
        new Frame(FrameType::HEADERS->value, 0x5, 1, (new Encoder())->encode([[':status', '204']])),
        static function (int $code) use (&$status): void { $status = $code; },
        static function (): void {},
    );
    expect($status)->toBe(204)
        ->and($healthy->done())->toBeTrue();
});
