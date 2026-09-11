<?php

declare(strict_types=1);

use Infocyph\Runwire\Http\Http3\ErrorCode;
use Infocyph\Runwire\Http\Http3\Frame;
use Infocyph\Runwire\Http\Http3\FrameType;
use Infocyph\Runwire\Http\Http3\FrameWriter;
use Infocyph\Runwire\Http\Http3\Http3Exception;
use Infocyph\Runwire\Http\Http3\Http3Limits;
use Infocyph\Runwire\Http\Http3\Internal\ConnectionState;
use Infocyph\Runwire\Http\Http3\Qpack\Encoder;
use Infocyph\Runwire\Http\Http3\SettingIdentifier;
use Infocyph\Runwire\Http\Http3\Settings;
use Infocyph\Runwire\Http\Http3\SettingsCodec;
use Infocyph\Runwire\Http\Http3\StreamType;
use Infocyph\Runwire\Http\Http3\VarIntCodec;

it('builds local HTTP/3 control and QPACK stream preambles', function (): void {
    $state = new ConnectionState(new Http3Limits(qpackMaxTableCapacity: 4_096, qpackMaxBlockedStreams: 8));
    $control = $state->localControlPreamble();
    $offset = 0;

    expect(VarIntCodec::decode($control, $offset))->toBe(StreamType::CONTROL->value)
        ->and(VarIntCodec::decode($state->localQpackEncoderPreamble()))->toBe(StreamType::QPACK_ENCODER->value)
        ->and(VarIntCodec::decode($state->localQpackDecoderPreamble()))->toBe(StreamType::QPACK_DECODER->value);
});

it('configures the response QPACK encoder from bounded peer settings', function (): void {
    $state = new ConnectionState(new Http3Limits(qpackMaxTableCapacity: 4_096, qpackMaxBlockedStreams: 8));
    $settings = new Settings([
        SettingIdentifier::QPACK_MAX_TABLE_CAPACITY->value => 8_192,
        SettingIdentifier::QPACK_BLOCKED_STREAMS->value => 32,
        SettingIdentifier::MAX_FIELD_SECTION_SIZE->value => 131_072,
    ]);
    $wire = VarIntCodec::encode(StreamType::CONTROL->value)
        . FrameWriter::encode(new Frame(FrameType::SETTINGS->value, SettingsCodec::encode($settings)));

    expect($state->responseEncoder())->toBeNull();
    $state->pushPeerUnidirectional(2, $wire);

    expect($state->peerSettings())->not->toBeNull()
        ->and($state->responseEncoder())->not->toBeNull()
        ->and($state->takeLocalQpackEncoderInstructions())->not->toBe('');
});

it('rejects duplicate critical streams and client push streams', function (): void {
    $state = new ConnectionState();
    $control = VarIntCodec::encode(StreamType::CONTROL->value);
    $state->pushPeerUnidirectional(2, $control);

    try {
        $state->pushPeerUnidirectional(6, $control);
        test()->fail('Duplicate HTTP/3 control stream should fail.');
    } catch (Http3Exception $exception) {
        expect($exception->errorCode)->toBe(ErrorCode::STREAM_CREATION_ERROR);
    }

    $state = new ConnectionState();

    try {
        $state->pushPeerUnidirectional(2, VarIntCodec::encode(StreamType::PUSH->value));
        test()->fail('Client-created HTTP/3 push stream should fail.');
    } catch (Http3Exception $exception) {
        expect($exception->errorCode)->toBe(ErrorCode::STREAM_CREATION_ERROR);
    }
});

it('discards unknown unidirectional stream types without a connection error', function (): void {
    $state = new ConnectionState();
    $unknownType = 0x21;
    $wire = VarIntCodec::encode($unknownType) . str_repeat('padding', 32);

    $state->pushPeerUnidirectional(2, substr($wire, 0, 1));
    $state->pushPeerUnidirectional(2, substr($wire, 1));
    $state->finishPeerUnidirectional(2);

    expect($state->peerSettings())->toBeNull();
});

it('tolerates a peer unidirectional stream closing before its type arrives', function (): void {
    $state = new ConnectionState();

    $state->finishPeerUnidirectional(2);

    expect($state->peerSettings())->toBeNull();
});

it('treats closure of HTTP/3 control and QPACK streams as critical', function (): void {
    foreach ([StreamType::CONTROL, StreamType::QPACK_ENCODER, StreamType::QPACK_DECODER] as $type) {
        $state = new ConnectionState();
        $state->pushPeerUnidirectional(2, VarIntCodec::encode($type->value));

        try {
            $state->finishPeerUnidirectional(2);
            test()->fail('Closing an HTTP/3 critical stream should fail.');
        } catch (Http3Exception $exception) {
            expect($exception->errorCode)->toBe(ErrorCode::CLOSED_CRITICAL_STREAM);
        }
    }
});

it('routes peer QPACK encoder instructions to blocked request streams', function (): void {
    $limits = new Http3Limits(qpackMaxTableCapacity: 220, qpackMaxBlockedStreams: 1);
    $state = new ConnectionState($limits);
    $peerEncoder = new Encoder(220, 1, dynamicTableCapacity: 220);

    $state->pushPeerUnidirectional(
        2,
        VarIntCodec::encode(StreamType::QPACK_ENCODER->value) . $peerEncoder->takeEncoderInstructions(),
    );

    $headers = $peerEncoder->encode([
        [':method', 'GET'],
        [':scheme', 'https'],
        [':authority', 'example.com'],
        [':path', '/'],
        ['x-dynamic', 'connection-state'],
    ], 0);
    $instructions = $peerEncoder->takeEncoderInstructions();
    $state->pushRequestStream(0, FrameWriter::encode(new Frame(FrameType::HEADERS->value, $headers->block)));

    expect($state->requestStream(0)?->blocked())->toBeTrue();

    $state->pushPeerUnidirectional(2, $instructions);

    expect($state->requestStream(0)?->blocked())->toBeFalse()
        ->and($state->requestStream(0)?->head()?->target)->toBe('/');
});

it('bounds concurrent request streams', function (): void {
    $state = new ConnectionState(new Http3Limits(maxConcurrentRequestStreams: 1));
    $peerEncoder = new Encoder(0, 0);
    $headers = $peerEncoder->encode([
        [':method', 'GET'],
        [':scheme', 'https'],
        [':authority', 'example.com'],
        [':path', '/'],
    ], 0)->block;

    $state->pushRequestStream(0, FrameWriter::encode(new Frame(FrameType::HEADERS->value, $headers)));

    try {
        $state->pushRequestStream(4, FrameWriter::encode(new Frame(FrameType::HEADERS->value, $headers)));
        test()->fail('Concurrent HTTP/3 request stream limit should fail.');
    } catch (Http3Exception $exception) {
        expect($exception->errorCode)->toBe(ErrorCode::REQUEST_REJECTED);
    }
});

it('bounds request and peer-unidirectional stream churn', function (): void {
    $peerEncoder = new Encoder(0, 0);
    $headers = $peerEncoder->encode([
        [':method', 'GET'],
        [':scheme', 'https'],
        [':authority', 'example.com'],
        [':path', '/'],
    ], 0)->block;
    $state = new ConnectionState(new Http3Limits(
        maxConcurrentRequestStreams: 1,
        maxRequestStreamsPerConnection: 1,
    ));
    $state->pushRequestStream(0, FrameWriter::encode(new Frame(FrameType::HEADERS->value, $headers)));
    $state->releaseRequestStream(0);

    try {
        $state->pushRequestStream(4, FrameWriter::encode(new Frame(FrameType::HEADERS->value, $headers)));
        test()->fail('HTTP/3 request stream churn limit should fail.');
    } catch (Http3Exception $exception) {
        expect($exception->errorCode)->toBe(ErrorCode::EXCESSIVE_LOAD);
    }

    $state = new ConnectionState(new Http3Limits(maxPeerUnidirectionalStreamsPerConnection: 1));
    $state->pushPeerUnidirectional(2, VarIntCodec::encode(0x21));
    $state->finishPeerUnidirectional(2);

    try {
        $state->pushPeerUnidirectional(6, VarIntCodec::encode(0x40));
        test()->fail('HTTP/3 peer unidirectional stream churn limit should fail.');
    } catch (Http3Exception $exception) {
        expect($exception->errorCode)->toBe(ErrorCode::EXCESSIVE_LOAD);
    }
});
