<?php

declare(strict_types=1);

use Infocyph\Runwire\Http\Http3\ControlStream;
use Infocyph\Runwire\Http\Http3\Enum\ErrorCode;
use Infocyph\Runwire\Http\Http3\Enum\FrameType;
use Infocyph\Runwire\Http\Http3\Enum\SettingIdentifier;
use Infocyph\Runwire\Http\Http3\Enum\StreamType;
use Infocyph\Runwire\Http\Http3\Frame;
use Infocyph\Runwire\Http\Http3\Http3Exception;
use Infocyph\Runwire\Http\Http3\Http3Limits;
use Infocyph\Runwire\Http\Http3\Settings;
use Infocyph\Runwire\Http\Http3\SettingsCodec;
use Infocyph\Runwire\Http\Http3\VarIntCodec;

it('round-trips HTTP/3 settings including QPACK limits and unknown settings', function (): void {
    $settings = new Settings([
        SettingIdentifier::QPACK_MAX_TABLE_CAPACITY->value => 4_096,
        SettingIdentifier::QPACK_BLOCKED_STREAMS->value => 16,
        SettingIdentifier::MAX_FIELD_SECTION_SIZE->value => 65_536,
        0x21 => 7,
    ]);

    $decoded = SettingsCodec::decode(SettingsCodec::encode($settings));

    expect($decoded->qpackMaxTableCapacity())->toBe(4_096)
        ->and($decoded->qpackBlockedStreams())->toBe(16)
        ->and($decoded->maxFieldSectionSize())->toBe(65_536)
        ->and($decoded->value(0x21))->toBe(7);
});

it('uses conservative HTTP/3 setting defaults when the peer omits them', function (): void {
    $settings = SettingsCodec::decode('');

    expect($settings->qpackMaxTableCapacity())->toBe(0)
        ->and($settings->qpackBlockedStreams())->toBe(0)
        ->and($settings->maxFieldSectionSize())->toBe(VarIntCodec::MAX_VALUE);
});

it('rejects HTTP/2-only reserved settings in HTTP/3', function (): void {
    $payload = VarIntCodec::encode(0x02) . VarIntCodec::encode(1);

    try {
        SettingsCodec::decode($payload);
        test()->fail('Reserved HTTP/3 setting should fail.');
    } catch (Http3Exception $exception) {
        expect($exception->errorCode)->toBe(ErrorCode::SETTINGS_ERROR);
    }
});

it('requires SETTINGS as the first control-stream frame', function (): void {
    $stream = new ControlStream();

    try {
        $stream->push((new Frame(FrameType::GOAWAY->value, "\x00"))->encode());
        test()->fail('Missing SETTINGS should fail.');
    } catch (Http3Exception $exception) {
        expect($exception->errorCode)->toBe(ErrorCode::MISSING_SETTINGS);
    }
});

it('rejects repeated SETTINGS and request frames on the control stream', function (): void {
    $stream = new ControlStream();
    $settingsFrame = (new Frame(FrameType::SETTINGS->value, ''))->encode();
    $stream->push($settingsFrame);

    try {
        $stream->push($settingsFrame);
        test()->fail('Repeated SETTINGS should fail.');
    } catch (Http3Exception $exception) {
        expect($exception->errorCode)->toBe(ErrorCode::FRAME_UNEXPECTED);
    }

    $stream = new ControlStream();
    $stream->push($settingsFrame);

    try {
        $stream->push((new Frame(FrameType::DATA->value, 'x'))->encode());
        test()->fail('DATA on the control stream should fail.');
    } catch (Http3Exception $exception) {
        expect($exception->errorCode)->toBe(ErrorCode::FRAME_UNEXPECTED);
    }
});

it('builds the local control stream preamble with SETTINGS first', function (): void {
    $settings = Settings::serverDefaults(new Http3Limits(qpackMaxTableCapacity: 8_192, qpackMaxBlockedStreams: 8));
    $wire = ControlStream::preamble($settings);
    $offset = 0;

    expect(VarIntCodec::decode($wire, $offset))->toBe(StreamType::CONTROL->value);

    $frames = (new Infocyph\Runwire\Http\Http3\FrameParser())->push(substr($wire, $offset));
    expect($frames)->toHaveCount(1)->and($frames[0]->knownType())->toBe(FrameType::SETTINGS);

    $decoded = SettingsCodec::decode($frames[0]->payload);
    expect($decoded->qpackMaxTableCapacity())->toBe(8_192)
        ->and($decoded->qpackBlockedStreams())->toBe(8);
});
