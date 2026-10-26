<?php

declare(strict_types=1);

use Infocyph\Runwire\Http\Client\Internal\Http3ClientControl;
use Infocyph\Runwire\Http\Http3\Enum\FrameType;
use Infocyph\Runwire\Http\Http3\Enum\SettingIdentifier;
use Infocyph\Runwire\Http\Http3\Frame;
use Infocyph\Runwire\Http\Http3\FrameParser;
use Infocyph\Runwire\Http\Http3\Http3Exception;
use Infocyph\Runwire\Http\Http3\Settings;
use Infocyph\Runwire\Http\Http3\SettingsCodec;
use Infocyph\Runwire\Http\Http3\VarIntCodec;

it('emits one outbound HTTP3 control preamble with zero QPACK blocking', function (): void {
    $client = new Http3ClientControl();
    $preamble = $client->start();
    $frames = (new FrameParser())->push(substr($preamble, 1));
    expect(ord($preamble[0]))->toBe(0)
        ->and($frames)->toHaveCount(1)
        ->and($frames[0]->type)->toBe(FrameType::SETTINGS->value)
        ->and(fn() => $client->start())->toThrow(LogicException::class, 'already started');

    $settings = SettingsCodec::decode($frames[0]->payload);
    expect($settings->qpackMaxTableCapacity())->toBe(0)
        ->and($settings->qpackBlockedStreams())->toBe(0)
        ->and($settings->maxFieldSectionSize())->toBe(65_536);
});

it('parses fragmented peer SETTINGS after a valid control type and rejects wrong stream types', function (): void {
    $client = new Http3ClientControl();
    expect(fn() => $client->receivePeerControl("\x00"))->toThrow(LogicException::class, 'must start');
    $client->start();

    $bytes = "\x00" . (new Frame(FrameType::SETTINGS->value, SettingsCodec::encode(
        new Settings([SettingIdentifier::QPACK_MAX_TABLE_CAPACITY->value => 0]),
    )))->encode();
    foreach (str_split($bytes) as $chunk) {
        $client->receivePeerControl($chunk);
    }

    expect($client->peerSettings())->not->toBeNull()
        ->and($client->peerSettings()->qpackMaxTableCapacity())->toBe(0);
    $wrong = new Http3ClientControl();
    $wrong->start();
    expect(fn() => $wrong->receivePeerControl("\x01"))->toThrow(Http3Exception::class, 'wrong stream type');
});

it('rejects DATA before SETTINGS, repeated SETTINGS and early critical control closure', function (): void {
    $client = new Http3ClientControl();
    $client->start();
    expect(fn() => $client->receivePeerControl("\x00" . (new Frame(FrameType::DATA->value, 'x'))->encode()))
        ->toThrow(Http3Exception::class, 'SETTINGS must be the first');

    $peer = new Http3ClientControl();
    $peer->start();
    $frame = (new Frame(FrameType::SETTINGS->value))->encode();
    $peer->receivePeerControl("\x00" . $frame);
    expect(fn() => $peer->receivePeerControl($frame))->toThrow(Http3Exception::class, 'only once')
        ->and(fn() => $peer->closePeerControl())->toThrow(Http3Exception::class, 'was closed');
});

it('buffers fragmented nonminimal or multibyte type identifiers without treating them as SETTINGS', function (): void {
    $client = new Http3ClientControl();
    $client->start();
    $type = VarIntCodec::encode(16_384);
    expect($client->receivePeerControl(substr($type, 0, 1)))->toBe([])
        ->and(fn() => $client->receivePeerControl(substr($type, 1)))->toThrow(Http3Exception::class, 'wrong stream type');
});

it('accepts a fragmented two-byte zero control stream type without treating it as non-control', function (): void {
    $client = new Http3ClientControl();
    $client->start();
    $frame = (new Frame(FrameType::SETTINGS->value))->encode();
    $wire = "\x40\x00" . $frame;
    foreach (str_split($wire) as $byte) {
        $client->receivePeerControl($byte);
    }
    expect($client->peerSettings())->not->toBeNull();
});

it('announces one local encoder and decoder QPACK critical stream only after SETTINGS', function (): void {
    $control = new Http3ClientControl();
    expect(fn() => $control->localQpackPreamble(\Infocyph\Runwire\Http\Http3\Enum\StreamType::QPACK_ENCODER))
        ->toThrow(LogicException::class, 'must start');
    $control->start();
    expect($control->localQpackPreamble(\Infocyph\Runwire\Http\Http3\Enum\StreamType::QPACK_ENCODER))
        ->toBe(chr(2))
        ->and($control->localQpackPreamble(\Infocyph\Runwire\Http\Http3\Enum\StreamType::QPACK_DECODER))
        ->toBe(chr(3))
        ->and(fn() => $control->localQpackPreamble(\Infocyph\Runwire\Http\Http3\Enum\StreamType::CONTROL))
        ->toThrow(LogicException::class, 'Only HTTP/3 QPACK');
});

it('validates monotonic peer GOAWAY request IDs on the same critical control stream', function (): void {
    $peer = new Http3ClientControl();
    $peer->start();
    $peer->receivePeerControl("\x00" . (new Frame(FrameType::SETTINGS->value))->encode());
    $peer->receivePeerControl(new Frame(FrameType::GOAWAY->value, VarIntCodec::encode(12))->encode());
    $peer->receivePeerControl(new Frame(FrameType::GOAWAY->value, VarIntCodec::encode(8))->encode());
    expect($peer->peerGoaway())->toBe(8);
    expect(fn() => $peer->receivePeerControl(new Frame(FrameType::GOAWAY->value, VarIntCodec::encode(16))->encode()))
        ->toThrow(Http3Exception::class, 'invalid or increasing');
});

it('rejects malformed HTTP/3 peer GOAWAY stream identities and trailing payload bytes', function (): void {
    foreach ([VarIntCodec::encode(1), "\x40", VarIntCodec::encode(8) . "\x00"] as $invalid) {
        $peer = new Http3ClientControl();
        $peer->start();
        $peer->receivePeerControl("\x00" . (new Frame(FrameType::SETTINGS->value))->encode());
        expect(fn() => $peer->receivePeerControl(new Frame(FrameType::GOAWAY->value, $invalid)->encode()))
            ->toThrow(Http3Exception::class, 'invalid or increasing');
    }
});

it('applies a peer HTTP/3 GOAWAY cutoff to the root without aborting a completed response', function (): void {
    $control = new Http3ClientControl();
    $control->start();
    $control->receivePeerControl("\x00" . (new Frame(FrameType::SETTINGS->value))->encode());
    expect($control->refusesRoot(0, false, false))->toBeFalse()
        ->and($control->refusesRoot(0, true, false))->toBeFalse();

    $control->receivePeerControl(new Frame(FrameType::GOAWAY->value, VarIntCodec::encode(8))->encode());
    expect($control->refusesRoot(0, true, false))->toBeFalse()
        ->and($control->refusesRoot(8, true, false))->toBeTrue()
        ->and($control->refusesRoot(8, true, true))->toBeFalse();

    $control->receivePeerControl(new Frame(FrameType::GOAWAY->value, VarIntCodec::encode(0))->encode());
    expect($control->refusesRoot(0, true, false))->toBeTrue()
        ->and($control->refusesRoot(0, false, false))->toBeFalse()
        ->and($control->refusesRoot(0, true, true))->toBeFalse();
});
