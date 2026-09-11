<?php

declare(strict_types=1);

use Infocyph\Runwire\Http\Headers;
use Infocyph\Runwire\Http\Http3\Frame;
use Infocyph\Runwire\Http\Http3\FrameParser;
use Infocyph\Runwire\Http\Http3\FrameType;
use Infocyph\Runwire\Http\Http3\FrameWriter;
use Infocyph\Runwire\Http\Http3\Http3Limits;
use Infocyph\Runwire\Http\Http3\Internal\ConnectionState;
use Infocyph\Runwire\Http\Http3\Internal\ResponseScheduler;
use Infocyph\Runwire\Http\Http3\SettingIdentifier;
use Infocyph\Runwire\Http\Http3\Settings;
use Infocyph\Runwire\Http\Http3\SettingsCodec;
use Infocyph\Runwire\Http\Http3\StreamType;
use Infocyph\Runwire\Http\Http3\VarIntCodec;
use Infocyph\Runwire\Network\WriteState;
use Infocyph\Runwire\Tests\Fixtures\Http3SchedulerTransport;

it('frames and finishes HTTP/3 responses without transport coupling', function (): void {
    $limits = new Http3Limits();
    $transport = new Http3SchedulerTransport();
    $scheduler = new ResponseScheduler(new ConnectionState($limits), $limits, $transport);
    $writer = $scheduler->writer(0, 'GET', static function (): void {});

    expect($writer->start(200, Headers::fromArray(['content-length' => '2']))->accepted())->toBeTrue()
        ->and($writer->end('ok')->accepted())->toBeTrue();

    $frames = new FrameParser($limits->maxFramePayloadBytes)->push($transport->requestBytes[0] ?? '');

    expect($frames)->toHaveCount(2)
        ->and($frames[0]->knownType())->toBe(FrameType::HEADERS)
        ->and($frames[1]->knownType())->toBe(FrameType::DATA)
        ->and($frames[1]->payload)->toBe('ok')
        ->and($transport->finished)->toBe([0]);
});

it('closes retained response writers when their HTTP/3 stream is discarded', function (): void {
    $limits = new Http3Limits();
    $scheduler = new ResponseScheduler(new ConnectionState($limits), $limits, new Http3SchedulerTransport());
    $writer = $scheduler->writer(0, 'GET', static function (): void {});

    $scheduler->discardStream(0);
    $result = $writer->write('orphan');

    expect($result->state)->toBe(WriteState::CLOSED)
        ->and($scheduler->responsePending(0))->toBeFalse();
});

it('retains exact unsent bytes across partial QUIC writes', function (): void {
    $limits = new Http3Limits(
        maxPendingResponseBytesPerStream: 256,
        responseLowWatermarkBytes: 16,
        responseHighWatermarkBytes: 64,
        maxPendingResponseBytesPerConnection: 512,
        maxQpackEncoderQueueBytes: 128,
        maxResponseFramePayloadBytes: 16,
        maxWritesPerFlush: 1,
    );
    $transport = new Http3SchedulerTransport();
    $scheduler = new ResponseScheduler(new ConnectionState($limits), $limits, $transport);
    $writer = $scheduler->writer(0, 'GET', static function (): void {});
    $writer->start(200);

    $transport->maxWriteBytes = 3;
    $result = $writer->end('abcdef');
    expect($result->state)->toBe(WriteState::PRESSURED);

    for ($attempt = 0; $attempt < 64 && $transport->finished === []; ++$attempt) {
        $scheduler->flush();
    }

    $frames = new FrameParser($limits->maxFramePayloadBytes)->push($transport->requestBytes[0] ?? '');
    $data = array_values(array_filter($frames, static fn(Frame $frame): bool => $frame->knownType() === FrameType::DATA));

    expect($data)->toHaveCount(1)
        ->and($data[0]->payload)->toBe('abcdef')
        ->and($transport->finished)->toBe([0]);
});

it('signals drain only after HTTP/3 response pressure is relieved', function (): void {
    $limits = new Http3Limits(
        maxPendingResponseBytesPerStream: 256,
        responseLowWatermarkBytes: 16,
        responseHighWatermarkBytes: 48,
        maxPendingResponseBytesPerConnection: 512,
        maxQpackEncoderQueueBytes: 128,
        maxResponseFramePayloadBytes: 16,
    );
    $transport = new Http3SchedulerTransport();
    $scheduler = new ResponseScheduler(new ConnectionState($limits), $limits, $transport);
    $writer = $scheduler->writer(0, 'GET', static function (): void {});
    $writer->start(200);
    $drains = 0;
    $writer->onDrain(static function () use (&$drains): void {
        ++$drains;
    });

    $transport->blocked = true;
    $result = $writer->write(str_repeat('x', 64));
    expect($result->state)->toBe(WriteState::PRESSURED)->and($drains)->toBe(0);

    $transport->blocked = false;
    $scheduler->flush();

    expect($drains)->toBe(1);
});

it('rejects response writes before exceeding the configured pending ceiling', function (): void {
    $limits = new Http3Limits(
        maxPendingResponseBytesPerStream: 96,
        responseLowWatermarkBytes: 16,
        responseHighWatermarkBytes: 64,
        maxPendingResponseBytesPerConnection: 192,
        maxQpackEncoderQueueBytes: 96,
        maxResponseFramePayloadBytes: 32,
    );
    $transport = new Http3SchedulerTransport();
    $scheduler = new ResponseScheduler(new ConnectionState($limits), $limits, $transport);
    $writer = $scheduler->writer(0, 'GET', static function (): void {});
    $writer->start(200);
    $transport->blocked = true;

    $result = $writer->write(str_repeat('x', 96));

    expect($result->state)->toBe(WriteState::REJECTED_LIMIT);
});

it('queues response QPACK encoder instructions when peer settings allow dynamic state', function (): void {
    $limits = new Http3Limits(qpackMaxTableCapacity: 220, qpackMaxBlockedStreams: 1);
    $state = new ConnectionState($limits);
    $settings = new Settings([
        SettingIdentifier::QPACK_MAX_TABLE_CAPACITY->value => 220,
        SettingIdentifier::QPACK_BLOCKED_STREAMS->value => 1,
    ]);
    $state->pushPeerUnidirectional(
        2,
        VarIntCodec::encode(StreamType::CONTROL->value)
            . FrameWriter::encode(new Frame(FrameType::SETTINGS->value, SettingsCodec::encode($settings))),
    );
    $transport = new Http3SchedulerTransport();
    $scheduler = new ResponseScheduler($state, $limits, $transport);
    $writer = $scheduler->writer(0, 'GET', static function (): void {});

    $result = $writer->start(200, Headers::fromArray(['x-dynamic' => 'response-scheduler']));

    expect($result->accepted())->toBeTrue()
        ->and($transport->qpackBytes)->not->toBe('')
        ->and($transport->requestBytes[0] ?? '')->not->toBe('');
});
