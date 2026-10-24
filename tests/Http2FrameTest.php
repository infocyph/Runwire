<?php

declare(strict_types=1);

use Infocyph\Runwire\Http\Http2\Enum\ErrorCode;
use Infocyph\Runwire\Http\Http2\Enum\FrameType;
use Infocyph\Runwire\Http\Http2\Frame;
use Infocyph\Runwire\Http\Http2\FrameParser;
use Infocyph\Runwire\Http\Http2\FrameWriter;
use Infocyph\Runwire\Http\Http2\Http2Limits;
use Infocyph\Runwire\Http\Http2\Internal\ConnectionError;
use Infocyph\Runwire\Http\Http2\PeerSettings;

it('parses a fully fragmented HTTP/2 frame', function (): void {
    $parser = new FrameParser();
    $wire = FrameWriter::encode(new Frame(FrameType::PING->value, 0x1, 0, '12345678'));
    $frames = [];

    foreach (str_split($wire) as $byte) {
        array_push($frames, ...$parser->push($byte));
    }

    expect($frames)->toHaveCount(1)
        ->and($frames[0]->knownType())->toBe(FrameType::PING)
        ->and($frames[0]->flags)->toBe(0x1)
        ->and($frames[0]->streamId)->toBe(0)
        ->and($frames[0]->payload)->toBe('12345678');
});

it('rejects frame lengths above the configured inbound bound before payload allocation', function (): void {
    $wire = "\x00\x40\x01" . chr(FrameType::DATA->value) . "\x00" . pack('N', 1);

    expect(fn () => (new FrameParser())->push($wire))
        ->toThrow(ConnectionError::class);
});

it('ignores the reserved stream-id bit when receiving frames', function (): void {
    $wire = "\x00\x00\x00" . chr(0xA) . "\x00" . pack('N', 0x8000_0001);
    $frames = (new FrameParser())->push($wire);

    expect($frames)->toHaveCount(1)->and($frames[0]->streamId)->toBe(1);
});

it('validates and applies peer settings with initial-window delta', function (): void {
    $settings = new PeerSettings();
    $payload = pack('nN', PeerSettings::HEADER_TABLE_SIZE, 2_048)
        . pack('nN', PeerSettings::ENABLE_PUSH, 0)
        . pack('nN', PeerSettings::INITIAL_WINDOW_SIZE, 32_768)
        . pack('nN', PeerSettings::MAX_FRAME_SIZE, 32_768);

    expect($settings->apply($payload))->toBe(32_768 - 65_535)
        ->and($settings->headerTableSize)->toBe(2_048)
        ->and($settings->enablePush)->toBeFalse()
        ->and($settings->initialWindowSize)->toBe(32_768)
        ->and($settings->maxFrameSize)->toBe(32_768);
});

it('builds bounded local settings from HTTP/2 limits', function (): void {
    $limits = new Http2Limits(
        maxConcurrentStreams: 32,
        maxPendingBodyBytesPerStream: 32_768,
        bodyLowWatermarkBytes: 8_192,
        bodyHighWatermarkBytes: 24_576,
    );
    $settings = PeerSettings::local($limits);

    expect($settings)->not->toHaveKey(PeerSettings::ENABLE_PUSH)
        ->and($settings[PeerSettings::MAX_CONCURRENT_STREAMS])->toBe(32)
        ->and($settings[PeerSettings::INITIAL_WINDOW_SIZE])->toBe(32_768);
});

it('builds protocol control frames without shelling out or hidden state', function (): void {
    expect(FrameWriter::rstStream(3, ErrorCode::CANCEL)->payload)->toBe(pack('N', ErrorCode::CANCEL->value))
        ->and(FrameWriter::windowUpdate(1, 1024)->payload)->toBe(pack('N', 1024))
        ->and(FrameWriter::goAway(7, ErrorCode::NO_ERROR)->payload)->toStartWith(pack('NN', 7, 0));
});


it('limits HTTP2 frame materialization per parser turn without losing buffered work', function (): void {
    $parser = new FrameParser();
    $wire = '';
    for ($i = 0; $i < 300; ++$i) {
        $wire .= FrameWriter::encode(new Frame(FrameType::PING->value, 0, 0, str_repeat('x', 8)));
    }

    $first = $parser->push($wire, 128);
    expect($first)->toHaveCount(128)
        ->and($parser->hasCompleteFrame())->toBeTrue();

    $second = $parser->push('', 128);
    $third = $parser->push('', 128);

    expect($second)->toHaveCount(128)
        ->and($third)->toHaveCount(44)
        ->and($parser->hasCompleteFrame())->toBeFalse()
        ->and($parser->bufferedBytes())->toBe(0);
});
