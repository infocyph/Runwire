<?php

declare(strict_types=1);

use Infocyph\Runwire\Http\Client\Internal\Http3ClientPeerStreamType;
use Infocyph\Runwire\Http\Http3\VarIntCodec;

it('routes nonminimal two-byte control type without confusing it with unknown streams', function (): void {
    $reader = new Http3ClientPeerStreamType();
    expect($reader->push("\x40"))->toBeNull();
    expect($reader->push("\x00\x04\x00"))->toBe([
        'type' => 0,
        'payload' => "\x04\x00",
        'wire' => "\x40\x00\x04\x00",
    ]);
});

it('classifies peer QPACK encoder and decoder critical streams across fragmented type bytes', function (): void {
    foreach ([2, 3] as $type) {
        $reader = new Http3ClientPeerStreamType();
        $wire = VarIntCodec::encode(16_384 + $type);
        foreach (str_split(substr($wire, 0, -1)) as $byte) {
            expect($reader->push($byte))->toBeNull();
        }
        expect($reader->push(substr($wire, -1) . "\x20"))->toBe([
            'type' => 16_384 + $type,
            'payload' => "\x20",
            'wire' => $wire . "\x20",
        ]);
    }
});

it('preserves the entire first read after a single-byte stream type', function (): void {
    $reader = new Http3ClientPeerStreamType();
    expect($reader->push("\x02\x20\x00"))->toBe([
        'type' => 2,
        'payload' => "\x20\x00",
        'wire' => "\x02\x20\x00",
    ]);
});

it('does not infer a stream type from an empty read', function (): void {
    $reader = new Http3ClientPeerStreamType();
    expect($reader->push(''))->toBeNull()
        ->and($reader->push("\x00"))->toBe([
            'type' => 0,
            'payload' => '',
            'wire' => "\x00",
        ]);
});
