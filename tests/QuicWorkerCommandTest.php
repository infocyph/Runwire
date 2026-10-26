<?php

declare(strict_types=1);

use Infocyph\Runwire\Network\Internal\QuicWorkerCommand;

it('round trips bounded QUIC worker stream commands and raw binary payloads', function (): void {
    $binary = "\x00\x01\xff\n" . random_bytes(64);
    $wire = QuicWorkerCommand::encode(123, 'write', 7, $binary, true);
    expect(QuicWorkerCommand::decode($wire))->toBe([
        'id' => 123,
        'op' => 'write',
        'stream' => 7,
        'data' => $binary,
        'fin' => true,
    ]);
    expect(QuicWorkerCommand::decode(QuicWorkerCommand::encode(124, 'open_bidi'))['op'])
        ->toBe('open_bidi');
    expect(QuicWorkerCommand::decode(QuicWorkerCommand::encode(125, 'open_uni'))['op'])
        ->toBe('open_uni');
});

it('rejects oversized or malformed QUIC worker commands before IPC dispatch', function (): void {
    expect(fn() => QuicWorkerCommand::encode(0, 'write', 1, 'x'))->toThrow(InvalidArgumentException::class)
        ->and(fn() => QuicWorkerCommand::encode(1, 'arbitrary_php', 1, 'x'))->toThrow(InvalidArgumentException::class)
        ->and(fn() => QuicWorkerCommand::encode(1, 'write', 0, 'x'))->toThrow(InvalidArgumentException::class)
        ->and(fn() => QuicWorkerCommand::encode(1, 'write', 1, str_repeat('x', 65_537)))->toThrow(InvalidArgumentException::class)
        ->and(fn() => QuicWorkerCommand::encode(1, 'close', 7))->toThrow(InvalidArgumentException::class)
        ->and(fn() => QuicWorkerCommand::encode(1, 'read', 5, '', true))->toThrow(InvalidArgumentException::class);
});

it('fails closed on malformed, untrusted or oversized worker frames', function (): void {
    $valid = QuicWorkerCommand::encode(5, 'write', 1, 'a');
    $invalid = [
        '',
        '{}',
        "{}\n",
        substr($valid, 0, -1),
        str_repeat('X', 90_001) . "\n",
        "{\"id\":5,\"op\":\"write\",\"stream\":1,\"data\":\"!!!\",\"fin\":false}\n",
        "{\"id\":5,\"op\":\"write\",\"stream\":1,\"data\":\"YQ==\",\"fin\":false,\"extra\":1}\n",
        "{\"id\":5,\"op\":\"read\",\"stream\":1,\"data\":\"YQ==\",\"fin\":false}\n",
    ];
    foreach ($invalid as $packet) {
        $rejected = false;
        try {
            QuicWorkerCommand::decode($packet);
        } catch (Throwable) {
            $rejected = true;
        }
        expect($rejected)->toBeTrue();
    }
});

it('accepts the maximum supported bounded QUIC worker upload frame', function (): void {
    $payload = str_repeat('a', QuicWorkerCommand::MAX_DATA_BYTES);
    $encoded = QuicWorkerCommand::encode(1, 'write', 2, $payload);
    $decoded = QuicWorkerCommand::decode($encoded);
    expect(strlen($encoded))->toBeLessThan(90_000)
        ->and(strlen($decoded['data']))->toBe(QuicWorkerCommand::MAX_DATA_BYTES)
        ->and($decoded['data'])->toBe($payload);
});

it('accepts idle native QUIC poll only with zero stream, no payload or FIN', function (): void {
    expect(QuicWorkerCommand::decode(QuicWorkerCommand::encode(8, 'poll')))->toBe([
        'id' => 8,
        'op' => 'poll',
        'stream' => 0,
        'data' => '',
        'fin' => false,
    ]);
    expect(fn() => QuicWorkerCommand::encode(9, 'poll', 1))->toThrow(InvalidArgumentException::class)
        ->and(fn() => QuicWorkerCommand::encode(10, 'poll', 0, 'x'))->toThrow(InvalidArgumentException::class)
        ->and(fn() => QuicWorkerCommand::encode(11, 'poll', 0, '', true))->toThrow(InvalidArgumentException::class);
});
