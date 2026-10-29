<?php

declare(strict_types=1);

use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Network\Internal\TlsHandshakeReadiness;

it('cleans read, write, and deadline watches when a handshake is cancelled', function (): void {
    $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    expect($sockets)->toBeArray()->toHaveCount(2);
    $loop = new SelectLoop();
    $owner = new TlsHandshakeReadiness($loop);
    $owner->start($sockets[0], 1.0, static function (): void {}, static function (): void {}, true);
    expect($loop->diagnostics()->readWatchers)->toBe(1)
        ->and($loop->diagnostics()->writeWatchers)->toBe(1)
        ->and($loop->diagnostics()->timersActive)->toBe(1);
    $owner->close();
    $owner->close();
    expect($loop->diagnostics()->readWatchers)->toBe(0)
        ->and($loop->diagnostics()->writeWatchers)->toBe(0)
        ->and($loop->diagnostics()->timersActive)->toBe(0);
    fclose($sockets[0]);
    fclose($sockets[1]);
});

it('keeps the server read-only handshake on a common bounded timer', function (): void {
    $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    expect($sockets)->toBeArray()->toHaveCount(2);
    $loop = new SelectLoop();
    $owner = new TlsHandshakeReadiness($loop);
    $timeouts = 0;
    $owner->start($sockets[0], 0.02, static function (): void {}, function () use (&$timeouts, $owner, $loop): void {
        ++$timeouts;
        $owner->close();
        $loop->stop();
    }, false);
    $loop->run();
    expect($timeouts)->toBe(1)
        ->and($loop->diagnostics()->readWatchers)->toBe(0)
        ->and($loop->diagnostics()->writeWatchers)->toBe(0)
        ->and($loop->diagnostics()->timersActive)->toBe(0);
    fclose($sockets[0]);
    fclose($sockets[1]);
});


it('bounds TLS handshake readiness watchers across repeated pre-negotiation cancellations', function (): void {
    $loop = new SelectLoop();
    $beforeMemory = memory_get_usage(true);
    $beforeFds = is_dir('/proc/self/fd') ? count(scandir('/proc/self/fd')) : null;

    for ($index = 0; $index < 32; ++$index) {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        expect($pair)->toBeArray()->toHaveCount(2);
        $owner = new TlsHandshakeReadiness($loop);
        $owner->start($pair[0], 0.1, static function (): void {}, static function (): void {}, true);
        expect($loop->diagnostics()->readWatchers)->toBe(1)
            ->and($loop->diagnostics()->writeWatchers)->toBe(1)
            ->and($loop->diagnostics()->timersActive)->toBe(1);
        $owner->close();
        $owner->close();
        fclose($pair[0]);
        fclose($pair[1]);
        expect($loop->diagnostics()->readWatchers)->toBe(0)
            ->and($loop->diagnostics()->writeWatchers)->toBe(0)
            ->and($loop->diagnostics()->timersActive)->toBe(0);
    }

    expect(memory_get_usage(true) - $beforeMemory)->toBeLessThan(8_388_608);
    if ($beforeFds !== null) {
        expect(count(scandir('/proc/self/fd')) - $beforeFds)->toBeLessThanOrEqual(2);
    }
});
