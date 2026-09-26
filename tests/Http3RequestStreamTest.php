<?php

declare(strict_types=1);

use Infocyph\Runwire\Http\Http3\Enum\ErrorCode;
use Infocyph\Runwire\Http\Http3\Enum\FrameType;
use Infocyph\Runwire\Http\Http3\Frame;
use Infocyph\Runwire\Http\Http3\Http3Exception;
use Infocyph\Runwire\Http\Http3\Http3Limits;
use Infocyph\Runwire\Http\Http3\Internal\RequestStream;
use Infocyph\Runwire\Http\Http3\Qpack\Decoder;
use Infocyph\Runwire\Http\Http3\Qpack\Encoder;

function http3RequestHeaders(Encoder $encoder, int $streamId, array $extra = []): string
{
    $fields = [
        [':method', 'POST'],
        [':scheme', 'https'],
        [':authority', 'example.com'],
        [':path', '/submit'],
        ...$extra,
    ];

    return $encoder->encode($fields, $streamId)->block;
}

it('parses HTTP/3 request HEADERS and DATA into bounded request-body state', function (): void {
    $encoder = new Encoder(0, 0);
    $decoder = new Decoder(0, 0);
    $stream = new RequestStream(0, $decoder, new Http3Limits());
    $wire = (new Frame(
        FrameType::HEADERS->value,
        http3RequestHeaders($encoder, 0, [['content-length', '5']]),
    ))->encode() . (new Frame(FrameType::DATA->value, 'hello'))->encode();

    $stream->push($wire);
    $stream->finish();

    expect($stream->head())->not->toBeNull()
        ->and($stream->head()?->method)->toBe('POST')
        ->and($stream->head()?->target)->toBe('/submit')
        ->and($stream->head()?->headers->first('host'))->toBe('example.com')
        ->and($stream->body()->read())->toBe('hello')
        ->and($stream->body()->eof())->toBeTrue()
        ->and($stream->finished())->toBeTrue();
});

it('rejects DATA before initial request HEADERS', function (): void {
    $stream = new RequestStream(0, new Decoder(0, 0), new Http3Limits());

    try {
        $stream->push((new Frame(FrameType::DATA->value, 'x'))->encode());
        test()->fail('DATA before request HEADERS should fail.');
    } catch (Http3Exception $exception) {
        expect($exception->errorCode)->toBe(ErrorCode::FRAME_UNEXPECTED);
    }
});

it('accepts trailing HEADERS and exposes request trailers after FIN', function (): void {
    $encoder = new Encoder(0, 0);
    $decoder = new Decoder(0, 0);
    $stream = new RequestStream(0, $decoder, new Http3Limits());
    $stream->push((new Frame(
        FrameType::HEADERS->value,
        http3RequestHeaders($encoder, 0, [['content-length', '3']]),
    ))->encode());
    $stream->push((new Frame(FrameType::DATA->value, 'abc'))->encode());
    $trailers = $encoder->encode([['x-checksum', 'ok']], 0)->block;
    $stream->push((new Frame(FrameType::HEADERS->value, $trailers))->encode());
    $stream->finish();

    expect($stream->trailers()?->first('x-checksum'))->toBe('ok')
        ->and($stream->body()->trailers()?->first('x-checksum'))->toBe('ok');
});

it('rejects DATA after trailing HEADERS', function (): void {
    $encoder = new Encoder(0, 0);
    $stream = new RequestStream(0, new Decoder(0, 0), new Http3Limits());
    $stream->push((new Frame(FrameType::HEADERS->value, http3RequestHeaders($encoder, 0)))->encode());
    $stream->push((new Frame(FrameType::HEADERS->value, $encoder->encode([['x-end', '1']], 0)->block))->encode());

    try {
        $stream->push((new Frame(FrameType::DATA->value, 'late'))->encode());
        test()->fail('DATA after trailers should fail.');
    } catch (Http3Exception $exception) {
        expect($exception->errorCode)->toBe(ErrorCode::FRAME_UNEXPECTED);
    }
});

it('buffers frames behind blocked QPACK state and resumes in order', function (): void {
    $encoder = new Encoder(220, 1, dynamicTableCapacity: 220);
    $decoder = new Decoder(220, 1);
    $decoder->pushEncoderInstructions($encoder->takeEncoderInstructions());
    $stream = new RequestStream(0, $decoder, new Http3Limits(qpackMaxTableCapacity: 220, qpackMaxBlockedStreams: 1));
    $headers = http3RequestHeaders($encoder, 0, [['x-dynamic', 'repeatable-value']]);
    $instructions = $encoder->takeEncoderInstructions();

    $stream->push((new Frame(FrameType::HEADERS->value, $headers))->encode());
    expect($stream->blocked())->toBeTrue()->and($stream->head())->toBeNull();

    $stream->push((new Frame(FrameType::DATA->value, 'queued'))->encode());
    $ready = $decoder->pushEncoderInstructions($instructions);
    expect($ready)->toHaveCount(1)->and($ready[0]->streamId)->toBe(0);

    $stream->resume($ready[0]->section);
    expect($stream->blocked())->toBeFalse()
        ->and($stream->head())->not->toBeNull()
        ->and($stream->body()->read())->toBe('queued');
});

it('defers FIN while QPACK is blocked and rejects later stream bytes', function (): void {
    $encoder = new Encoder(220, 1, dynamicTableCapacity: 220);
    $decoder = new Decoder(220, 1);
    $decoder->pushEncoderInstructions($encoder->takeEncoderInstructions());
    $stream = new RequestStream(0, $decoder, new Http3Limits(qpackMaxTableCapacity: 220, qpackMaxBlockedStreams: 1));
    $headers = http3RequestHeaders($encoder, 0, [['x-dynamic', 'deferred-fin']]);
    $instructions = $encoder->takeEncoderInstructions();

    $stream->push((new Frame(FrameType::HEADERS->value, $headers))->encode());
    $stream->finish();
    expect($stream->finished())->toBeFalse();

    try {
        $stream->push((new Frame(FrameType::DATA->value, 'late'))->encode());
        test()->fail('Bytes after HTTP/3 stream FIN should fail even while QPACK is blocked.');
    } catch (Http3Exception $exception) {
        expect($exception->errorCode)->toBe(ErrorCode::FRAME_UNEXPECTED);
    }

    $ready = $decoder->pushEncoderInstructions($instructions);
    $stream->resume($ready[0]->section);

    expect($stream->finished())->toBeTrue()->and($stream->body()->eof())->toBeTrue();
});

it('enforces Content-Length at request stream completion', function (): void {
    $encoder = new Encoder(0, 0);
    $stream = new RequestStream(0, new Decoder(0, 0), new Http3Limits());
    $stream->push((new Frame(
        FrameType::HEADERS->value,
        http3RequestHeaders($encoder, 0, [['content-length', '4']]),
    ))->encode());
    $stream->push((new Frame(FrameType::DATA->value, 'abc'))->encode());

    try {
        $stream->finish();
        test()->fail('Mismatched HTTP/3 Content-Length should fail.');
    } catch (Http3Exception $exception) {
        expect($exception->errorCode)->toBe(ErrorCode::MESSAGE_ERROR);
    }
});

it('rejects control frames on a request stream and incomplete request FIN', function (): void {
    $stream = new RequestStream(0, new Decoder(0, 0), new Http3Limits());

    try {
        $stream->push((new Frame(FrameType::SETTINGS->value, ''))->encode());
        test()->fail('SETTINGS on a request stream should fail.');
    } catch (Http3Exception $exception) {
        expect($exception->errorCode)->toBe(ErrorCode::FRAME_UNEXPECTED);
    }

    $stream = new RequestStream(4, new Decoder(0, 0), new Http3Limits());

    try {
        $stream->finish();
        test()->fail('FIN before request HEADERS should fail.');
    } catch (Http3Exception $exception) {
        expect($exception->errorCode)->toBe(ErrorCode::REQUEST_INCOMPLETE);
    }
});


it('delivers a DATA frame larger than body buffer capacity when the consumer drains incrementally', function (): void {
    $encoder = new Encoder(0, 0);
    $limits = new Http3Limits(
        maxPendingBodyBytesPerStream: 8,
        bodyLowWatermarkBytes: 2,
        bodyHighWatermarkBytes: 6,
        streamReadChunkBytes: 4,
    );
    $stream = new RequestStream(0, new Decoder(0, 0), $limits);
    $stream->push((new Frame(
        FrameType::HEADERS->value,
        http3RequestHeaders($encoder, 0, [['content-length', '20']]),
    ))->encode());

    $received = '';
    $stream->body()->onData(static function ($body) use (&$received): void {
        $received .= $body->read();
    });

    $stream->push((new Frame(FrameType::DATA->value, 'abcdefghijklmnopqrst'))->encode());
    $stream->finish();

    expect($received)->toBe('abcdefghijklmnopqrst')
        ->and($stream->receivedBodyBytes())->toBe(20)
        ->and($stream->finished())->toBeTrue();
});

it('defers FIN until pressured DATA remainder is consumed', function (): void {
    $encoder = new Encoder(0, 0);
    $limits = new Http3Limits(
        maxPendingBodyBytesPerStream: 8,
        bodyLowWatermarkBytes: 2,
        bodyHighWatermarkBytes: 6,
        streamReadChunkBytes: 4,
    );
    $stream = new RequestStream(0, new Decoder(0, 0), $limits);
    $stream->push(
        (new Frame(
            FrameType::HEADERS->value,
            http3RequestHeaders($encoder, 0, [['content-length', '12']]),
        ))->encode()
        . (new Frame(FrameType::DATA->value, 'abcdefghijkl'))->encode(),
    );
    $stream->finish();

    expect($stream->pressured())->toBeTrue()
        ->and($stream->finished())->toBeFalse();

    $received = '';
    $stream->body()->onData(static function ($body) use (&$received): void {
        $received .= $body->read();
    });

    expect($received)->toBe('abcdefghijkl')
        ->and($stream->pressured())->toBeFalse()
        ->and($stream->finished())->toBeTrue()
        ->and($stream->body()->eof())->toBeTrue();
});


it('preserves buffered QPACK frames across body pressure before FIN completion', function (): void {
    $encoder = new Encoder(220, 1, dynamicTableCapacity: 220);
    $decoder = new Decoder(220, 1);
    $decoder->pushEncoderInstructions($encoder->takeEncoderInstructions());
    $limits = new Http3Limits(
        qpackMaxTableCapacity: 220,
        qpackMaxBlockedStreams: 1,
        maxPendingBodyBytesPerStream: 8,
        bodyLowWatermarkBytes: 2,
        bodyHighWatermarkBytes: 6,
        streamReadChunkBytes: 4,
    );
    $stream = new RequestStream(0, $decoder, $limits);
    $headers = http3RequestHeaders($encoder, 0, [
        ['content-length', '12'],
        ['x-dynamic', 'pressure-resume'],
    ]);
    $instructions = $encoder->takeEncoderInstructions();

    $stream->push((new Frame(FrameType::HEADERS->value, $headers))->encode());
    expect($stream->blocked())->toBeTrue();

    $stream->push(
        (new Frame(FrameType::DATA->value, 'abcdefghijkl'))->encode()
        . (new Frame(
            FrameType::HEADERS->value,
            (new Encoder(0, 0))->encode([['x-end', 'yes']], 0)->block,
        ))->encode(),
    );
    $stream->finish();

    $ready = $decoder->pushEncoderInstructions($instructions);
    $stream->resume($ready[0]->section);

    expect($stream->pressured())->toBeTrue()
        ->and($stream->finished())->toBeFalse();

    $received = '';
    $stream->body()->onData(static function ($body) use (&$received): void {
        $received .= $body->read();
    });

    expect($received)->toBe('abcdefghijkl')
        ->and($stream->trailers()?->first('x-end'))->toBe('yes')
        ->and($stream->finished())->toBeTrue()
        ->and($stream->body()->eof())->toBeTrue();
});


it('classifies HTTP3 header body and QPACK progress deadline expiry', function (): void {
    $limits = new Http3Limits(
        requestHeaderTimeoutSeconds: 0.001,
        requestBodyIdleTimeoutSeconds: 0.001,
        qpackBlockedTimeoutSeconds: 0.001,
    );

    $headersPending = new RequestStream(0, new Decoder(0, 0), $limits);
    expect($headersPending->timeoutReason(PHP_INT_MAX))->toBe('headers');

    $encoder = new Encoder(0, 0);
    $bodyPending = new RequestStream(0, new Decoder(0, 0), $limits);
    $bodyPending->push((new Frame(
        FrameType::HEADERS->value,
        http3RequestHeaders($encoder, 0),
    ))->encode());
    expect($bodyPending->timeoutReason(PHP_INT_MAX))->toBe('body');

    $dynamicEncoder = new Encoder(220, 1, dynamicTableCapacity: 220);
    $dynamicDecoder = new Decoder(220, 1);
    $dynamicDecoder->pushEncoderInstructions($dynamicEncoder->takeEncoderInstructions());
    $blocked = new RequestStream(0, $dynamicDecoder, new Http3Limits(
        qpackMaxTableCapacity: 220,
        qpackMaxBlockedStreams: 1,
        qpackBlockedTimeoutSeconds: 0.001,
    ));
    $blocked->push((new Frame(
        FrameType::HEADERS->value,
        http3RequestHeaders($dynamicEncoder, 0, [['x-dynamic', 'repeatable-value']]),
    ))->encode());

    expect($blocked->blocked())->toBeTrue()
        ->and($blocked->timeoutReason(PHP_INT_MAX))->toBe('qpack');
});
