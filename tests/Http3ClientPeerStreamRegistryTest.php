<?php

declare(strict_types=1);

use Infocyph\Runwire\Http\Client\Internal\Http3ClientPeerStreamRegistry;
use Infocyph\Runwire\Http\Http3\Http3Exception;

it('tracks fragmented nonminimal control type without consuming first frame payload', function (): void {
    $registry = new Http3ClientPeerStreamRegistry();
    expect($registry->push(3, "\x40", false))->toBeNull();
    expect($registry->push(3, "\x00\x04\x00", false))->toBe([
        'type' => 0, 'payload' => "\x04\x00",
        'wire' => "\x40\x00\x04\x00", 'initial' => true,
    ]);
    expect($registry->push(3, "\x01", false))->toBe([
        'type' => 0, 'payload' => "\x01", 'wire' => "\x01", 'initial' => false,
    ]);
});

it('rejects second peer control and QPACK critical streams independently', function (): void {
    foreach ([0, 2, 3] as $type) {
        $registry = new Http3ClientPeerStreamRegistry();
        $registry->push(3, chr($type), false);
        expect(fn() => $registry->push(7, chr($type), false))->toThrow(Http3Exception::class, 'Duplicate');
    }
});

it('tracks simultaneous distinct control and QPACK streams', function (): void {
    $registry = new Http3ClientPeerStreamRegistry();
    foreach ([0, 2, 3] as $index => $type) {
        expect($registry->push(3 + 4 * $index, chr($type), false))->toMatchArray([
            'type' => $type, 'initial' => true,
        ]);
    }
});

it('rejects premature stream FIN before a fragmented type becomes complete', function (): void {
    $registry = new Http3ClientPeerStreamRegistry();
    expect(fn() => $registry->push(3, "\x80", true))->toThrow(Http3Exception::class, 'before its type');
});
