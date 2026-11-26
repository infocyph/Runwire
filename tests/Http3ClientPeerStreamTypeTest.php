<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Tests;

use Infocyph\Runwire\Http\Client\Internal\Http3ClientPeerStreamRegistry;
use Infocyph\Runwire\Http\Http3\VarIntCodec;


it('routes nonminimal two-byte control type without confusing it with unknown streams', function (): void {
    runwireOwnedHttp3ClientPeerStreamTypeTestCase01();
});

/**
 * @throws \Throwable
 */
function runwireOwnedHttp3ClientPeerStreamTypeTestCase01(): void
{
    $reader = new Http3ClientPeerStreamRegistry();
    expect($reader->push(3, "\x40", false))->toBeNull();
    expect($reader->push(3, "\x00\x04\x00", false))->toBe([
        'type' => 0,
        'payload' => "\x04\x00",
        'wire' => "\x40\x00\x04\x00",
        'initial' => true,
    ]);
}

it('classifies peer QPACK encoder and decoder critical streams across fragmented type bytes', function (): void {
    runwireOwnedHttp3ClientPeerStreamTypeTestCase02();
});

/**
 * @throws \Throwable
 */
function runwireOwnedHttp3ClientPeerStreamTypeTestCase02(): void
{
    foreach ([2, 3] as $type) {
        $reader = new Http3ClientPeerStreamRegistry();
        $wire = VarIntCodec::encode(16_384 + $type);
        foreach (str_split(substr($wire, 0, -1)) as $byte) {
            expect($reader->push(3, $byte, false))->toBeNull();
        }
        expect($reader->push(3, substr($wire, -1, false) . "\x20"))->toBe([
            'type' => 16_384 + $type,
            'payload' => "\x20",
            'wire' => $wire . "\x20",
            'initial' => true,
        ]);
    }
}

it('preserves the entire first read after a single-byte stream type', function (): void {
    runwireOwnedHttp3ClientPeerStreamTypeTestCase03();
});

/**
 * @throws \Throwable
 */
function runwireOwnedHttp3ClientPeerStreamTypeTestCase03(): void
{
    $reader = new Http3ClientPeerStreamRegistry();
    expect($reader->push(3, "\x02\x20\x00", false))->toBe([
        'type' => 2,
        'payload' => "\x20\x00",
        'wire' => "\x02\x20\x00",
        'initial' => true,
    ]);
}

it('does not infer a stream type from an empty read', function (): void {
    runwireOwnedHttp3ClientPeerStreamTypeTestCase04();
});

/**
 * @throws \Throwable
 */
function runwireOwnedHttp3ClientPeerStreamTypeTestCase04(): void
{
    $reader = new Http3ClientPeerStreamRegistry();
    expect($reader->push(3, '', false))->toBeNull()
        ->and($reader->push(3, "\x00", false))->toBe([
            'type' => 0,
            'payload' => '',
            'wire' => "\x00",
            'initial' => true,
        ]);
}
