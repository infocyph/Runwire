<?php

declare(strict_types=1);

use Infocyph\Runwire\Http\Http3\Enum\ErrorCode;
use Infocyph\Runwire\Http\Http3\Enum\FrameType;
use Infocyph\Runwire\Http\Http3\Enum\StreamType;
use Infocyph\Runwire\Http\Http3\Frame;
use Infocyph\Runwire\Http\Http3\FrameParser;
use Infocyph\Runwire\Http\Http3\Http3Exception;
use Infocyph\Runwire\Http\Http3\Http3Limits;
use Infocyph\Runwire\Http\Http3\Internal\ConnectionState;
use Infocyph\Runwire\Http\Http3\Internal\RequestStream;
use Infocyph\Runwire\Http\Http3\Qpack\Decoder;
use Infocyph\Runwire\Http\Http3\Qpack\Encoder;
use Infocyph\Runwire\Http\Http3\VarIntCodec;

/** @param callable(): void $action */
function expectHttp3Fault(callable $action, ErrorCode $expected): void
{
    try {
        $action();
        test()->fail(sprintf('Expected HTTP/3 fault %s.', $expected->name));
    } catch (Http3Exception $exception) {
        expect($exception->errorCode)->toBe($expected);
    }
}

function abuseRequestHeaders(Encoder $encoder, int $streamId, array $extra = []): string
{
    return $encoder->encode([
        [':method', 'POST'],
        [':scheme', 'https'],
        [':authority', 'example.test'],
        [':path', '/abuse'],
        ...$extra,
    ], $streamId)->block;
}

it('rejects an oversized frame from its declared length before payload allocation', function (): void {
    $parser = new FrameParser(8);
    $wire = VarIntCodec::encode(FrameType::DATA->value) . VarIntCodec::encode(9);

    expectHttp3Fault(
        static fn() => $parser->push($wire),
        ErrorCode::EXCESSIVE_LOAD,
    );
    expect($parser->bufferedBytes())->toBeLessThanOrEqual(16);
});

it('contains request body floods at both total-body and pending-buffer boundaries', function (): void {
    $encoder = new Encoder(0, 0);
    $totalLimit = new Http3Limits(
        maxBodyBytes: 4,
        maxPendingBodyBytesPerStream: 8,
        bodyLowWatermarkBytes: 2,
        bodyHighWatermarkBytes: 6,
    );
    $stream = new RequestStream(0, new Decoder(0, 0), $totalLimit);
    $stream->push((new Frame(
        FrameType::HEADERS->value,
        abuseRequestHeaders($encoder, 0),
    ))->encode());

    expectHttp3Fault(
        static fn() => $stream->push((new Frame(FrameType::DATA->value, '12345'))->encode()),
        ErrorCode::EXCESSIVE_LOAD,
    );

    $bufferLimit = new Http3Limits(
        maxBodyBytes: 32,
        maxPendingBodyBytesPerStream: 4,
        bodyLowWatermarkBytes: 1,
        bodyHighWatermarkBytes: 3,
    );
    $stream = new RequestStream(4, new Decoder(0, 0), $bufferLimit);
    $stream->push((new Frame(
        FrameType::HEADERS->value,
        abuseRequestHeaders($encoder, 4),
    ))->encode());

    $stream->push((new Frame(FrameType::DATA->value, '12345'))->encode());

    expect($stream->pressured())->toBeTrue()
        ->and($stream->body()->bufferedBytes())->toBeLessThanOrEqual(4);

    $received = $stream->body()->read() . $stream->body()->read();

    expect($received)->toBe('12345')
        ->and($stream->pressured())->toBeFalse();
});

it('bounds queued request bytes while QPACK decoding is blocked', function (): void {
    $limits = new Http3Limits(
        qpackMaxTableCapacity: 220,
        qpackMaxBlockedStreams: 1,
        maxBlockedRequestStreamBytes: 24,
    );
    $encoder = new Encoder(220, 1, dynamicTableCapacity: 220);
    $decoder = new Decoder(220, 1);
    $decoder->pushEncoderInstructions($encoder->takeEncoderInstructions());
    $stream = new RequestStream(0, $decoder, $limits);
    $headers = abuseRequestHeaders($encoder, 0, [['x-dynamic', 'blocked-value']]);

    $stream->push((new Frame(FrameType::HEADERS->value, $headers))->encode());
    expect($stream->blocked())->toBeTrue();

    expectHttp3Fault(
        static fn() => $stream->push((new Frame(FrameType::DATA->value, '123456789'))->encode()),
        ErrorCode::EXCESSIVE_LOAD,
    );
});

it('classifies truncated frames and lost critical streams without ambiguous errors', function (): void {
    $encoder = new Encoder(0, 0);
    $stream = new RequestStream(0, new Decoder(0, 0), new Http3Limits());
    $frame = (new Frame(
        FrameType::HEADERS->value,
        abuseRequestHeaders($encoder, 0),
    ))->encode();
    $stream->push(substr($frame, 0, -1));

    expectHttp3Fault(
        static fn() => $stream->finish(),
        ErrorCode::FRAME_ERROR,
    );

    foreach ([StreamType::CONTROL, StreamType::QPACK_ENCODER, StreamType::QPACK_DECODER] as $type) {
        $state = new ConnectionState();
        $state->pushPeerUnidirectional(2, VarIntCodec::encode($type->value));
        expectHttp3Fault(
            static fn() => $state->finishPeerUnidirectional(2),
            ErrorCode::CLOSED_CRITICAL_STREAM,
        );
    }
});

it('bounds request-stream churn after completed state is released', function (): void {
    $limits = new Http3Limits(
        maxConcurrentRequestStreams: 1,
        maxRequestStreamsPerConnection: 2,
    );
    $state = new ConnectionState($limits);
    $encoder = new Encoder(0, 0);

    foreach ([0, 4] as $streamId) {
        $state->pushRequestStream($streamId, (new Frame(
            FrameType::HEADERS->value,
            abuseRequestHeaders($encoder, $streamId),
        ))->encode());
        $state->finishRequestStream($streamId);
        $state->releaseRequestStream($streamId);
    }

    expectHttp3Fault(
        static fn() => $state->pushRequestStream(8, (new Frame(
            FrameType::HEADERS->value,
            abuseRequestHeaders($encoder, 8),
        ))->encode()),
        ErrorCode::EXCESSIVE_LOAD,
    );
});
