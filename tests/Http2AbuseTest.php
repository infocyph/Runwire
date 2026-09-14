<?php

declare(strict_types=1);

use Infocyph\Runwire\Http\Http2\Enum\ErrorCode;
use Infocyph\Runwire\Http\Http2\Enum\FrameType;
use Infocyph\Runwire\Http\Http2\Frame;
use Infocyph\Runwire\Http\Http2\FrameWriter;
use Infocyph\Runwire\Http\Http2\Hpack\Encoder;
use Infocyph\Runwire\Http\Http2\Http2Limits;
use Infocyph\Runwire\Http\Http2\Internal\HeaderValidationException;
use Infocyph\Runwire\Http\Http2\Internal\RequestHeaderValidator;
use Infocyph\Runwire\Http\HttpRequest;
use Infocyph\Runwire\Http\ResponseWriterInterface;

require_once __DIR__ . '/Support/Http2TestSupport.php';

function runwireH2GoAwayError(string $wire, ?Http2Limits $limits = null): ?int
{
    [$response] = runwireH2Exchange($wire, static function (): void {}, $limits);
    foreach (array_reverse(runwireH2Frames($response)) as $frame) {
        if ($frame->knownType() === FrameType::GOAWAY && strlen($frame->payload) >= 8) {
            return unpack('N', substr($frame->payload, 4, 4))[1];
        }
    }

    return null;
}

function runwireH2ResetError(string $wire, int $streamId, callable $handler, ?Http2Limits $limits = null): ?int
{
    [$response] = runwireH2Exchange($wire, $handler, $limits);
    foreach (runwireH2Frames($response) as $frame) {
        if ($frame->knownType() === FrameType::RST_STREAM && $frame->streamId === $streamId) {
            return unpack('N', $frame->payload)[1];
        }
    }

    return null;
}

it('maps malformed connection frames to exact HTTP/2 connection errors', function (): void {
    expect(runwireH2GoAwayError(
        runwireH2ClientPrelude() . FrameWriter::encode(new Frame(FrameType::PING->value, 0, 1, '12345678')),
    ))->toBe(ErrorCode::PROTOCOL_ERROR->value);

    expect(runwireH2GoAwayError(
        runwireH2ClientPrelude() . FrameWriter::encode(new Frame(FrameType::PING->value, 0, 0, 'bad')),
    ))->toBe(ErrorCode::FRAME_SIZE_ERROR->value);
});

it('rejects interrupted header blocks as a connection protocol error', function (): void {
    $block = (new Encoder())->encode([
        [':method', 'GET'], [':scheme', 'https'], [':authority', 'x'], [':path', '/'],
    ]);
    $wire = runwireH2ClientPrelude()
        . FrameWriter::encode(new Frame(FrameType::HEADERS->value, 0x1, 1, substr($block, 0, 1)))
        . FrameWriter::encode(new Frame(FrameType::PING->value, 0, 0, '12345678'));

    expect(runwireH2GoAwayError($wire))->toBe(ErrorCode::PROTOCOL_ERROR->value);
});

it('bounds PING control-frame work per connection', function (): void {
    $wire = runwireH2ClientPrelude();
    for ($i = 0; $i < 4; ++$i) {
        $wire .= FrameWriter::encode(new Frame(FrameType::PING->value, 0, 0, '12345678'));
    }

    expect(runwireH2GoAwayError(
        $wire,
        new Http2Limits(maxControlFramesPerSecond: 2),
    ))->toBe(ErrorCode::ENHANCE_YOUR_CALM->value);
});

it('bounds SETTINGS control-frame work per connection', function (): void {
    $wire = runwireH2ClientPrelude();
    for ($i = 0; $i < 4; ++$i) {
        $wire .= FrameWriter::encode(FrameWriter::settings([]));
    }

    expect(runwireH2GoAwayError(
        $wire,
        new Http2Limits(maxControlFramesPerSecond: 2),
    ))->toBe(ErrorCode::ENHANCE_YOUR_CALM->value);
});

it('rejects oversized decoded header lists before application dispatch', function (): void {
    $encoder = new Encoder();
    $block = $encoder->encode([
        [':method', 'GET'],
        [':scheme', 'https'],
        [':authority', 'example.test'],
        [':path', '/'],
        ['x-large', str_repeat('a', 128)],
    ]);
    $called = false;
    $wire = runwireH2ClientPrelude()
        . FrameWriter::encode(new Frame(FrameType::HEADERS->value, 0x5, 1, $block));

    $reset = runwireH2ResetError(
        $wire,
        1,
        static function () use (&$called): void { $called = true; },
        new Http2Limits(maxHeaderListBytes: 64),
    );

    expect($called)->toBeFalse()
        ->and($reset)->not->toBeNull();
});

it('bounds lifetime request-stream creation', function (): void {
    $wire = runwireH2ClientPrelude()
        . runwireH2Headers(1, '/one')
        . runwireH2Headers(3, '/two')
        . runwireH2Headers(5, '/three');

    expect(runwireH2GoAwayError(
        $wire,
        new Http2Limits(maxStreamsPerConnection: 2),
    ))->toBe(ErrorCode::ENHANCE_YOUR_CALM->value);
});

it('delivers synchronous END_STREAM before a response can clean up the stream', function (): void {
    $ended = 0;
    runwireH2Exchange(
        runwireH2ClientPrelude() . runwireH2Headers(1, '/end'),
        static function (HttpRequest $request, ResponseWriterInterface $writer) use (&$ended): void {
            $request->body->onEnd(static function () use (&$ended): void { ++$ended; });
            $writer->end('ok');
        },
    );

    expect($ended)->toBe(1);
});

it('cancels request bodies on reset and discards post-response inbound data', function (): void {
    $cancelled = 0;
    $ended = 0;
    $resetWire = runwireH2ClientPrelude()
        . runwireH2Headers(1, '/rst', false)
        . FrameWriter::encode(FrameWriter::rstStream(1, ErrorCode::CANCEL));

    runwireH2Exchange($resetWire, static function (HttpRequest $request) use (&$cancelled, &$ended): void {
        $request->body->onCancel(static function () use (&$cancelled): void { ++$cancelled; });
        $request->body->onEnd(static function () use (&$ended): void { ++$ended; });
    });

    expect($cancelled)->toBe(1)->and($ended)->toBe(0);

    $cancelled = 0;
    $earlyWire = runwireH2ClientPrelude()
        . runwireH2Headers(1, '/early', false)
        . FrameWriter::encode(new Frame(FrameType::DATA->value, 0x1, 1, 'later'));
    $rst = runwireH2ResetError($earlyWire, 1, static function (HttpRequest $request, ResponseWriterInterface $writer) use (&$cancelled): void {
        $request->body->onCancel(static function () use (&$cancelled): void { ++$cancelled; });
        $writer->end('early');
    });

    expect($cancelled)->toBe(1)->and($rst)->toBeNull();
});

it('rejects invalid HTTP/2 pseudo-header and connection-field combinations', function (): void {
    $validator = new RequestHeaderValidator();
    $invalid = [
        [[':method', 'GET'], [':scheme', 'https'], [':path', '/'], ['X-Test', 'x']],
        [[':method', 'GET'], ['x', '1'], [':scheme', 'https'], [':path', '/']],
        [[':method', 'GET'], [':method', 'POST'], [':scheme', 'https'], [':path', '/']],
        [[':method', 'GET'], [':scheme', 'https'], [':path', '/'], ['connection', 'close']],
        [[':method', 'GET'], [':scheme', 'https'], [':path', '/'], ['te', 'gzip']],
        [[':method', 'GET'], [':scheme', 'https'], [':authority', 'a'], [':path', '/'], ['host', 'b']],
    ];

    foreach ($invalid as $fields) {
        expect(fn () => $validator->request($fields))->toThrow(HeaderValidationException::class);
    }
    expect(fn () => $validator->trailers([[':path', '/']]))->toThrow(HeaderValidationException::class);
});
