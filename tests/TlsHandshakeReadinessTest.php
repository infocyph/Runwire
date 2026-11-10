<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Tests;

use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Network\Internal\TlsHandshakeReadiness;

/**
 * @throws \RuntimeException
 */
function runwireTlsHandshakeFdCount(): int
{
    $entries = scandir('/proc/self/fd');
    if ($entries === false) {
        throw new \RuntimeException('Unable to inspect TLS handshake fixture descriptors.');
    }

    return count($entries);
}

it('cleans read, write, and deadline watches when a handshake is cancelled', function (): void {
    runwireOwnedTlsHandshakeReadinessTestCase01();
});

/**
 * @throws \Throwable
 */
function runwireOwnedTlsHandshakeReadinessTestCase01(): void
{
    $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    \PHPUnit\Framework\Assert::assertIsArray($sockets);
    expect($sockets)->toHaveCount(2);
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
}

it('keeps the server read-only handshake on a common bounded timer', function (): void {
    runwireOwnedTlsHandshakeReadinessTestCase02();
});

/**
 * @throws \Throwable
 */
function runwireOwnedTlsHandshakeReadinessTestCase02(): void
{
    $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    \PHPUnit\Framework\Assert::assertIsArray($sockets);
    expect($sockets)->toHaveCount(2);
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
}


it('bounds TLS handshake readiness watchers across repeated pre-negotiation cancellations', function (): void {
    runwireOwnedTlsHandshakeReadinessTestCase03();
});

/**
 * @throws \Throwable
 */
function runwireOwnedTlsHandshakeReadinessTestCase03(): void
{
    $loop = new SelectLoop();
    $beforeMemory = memory_get_usage(true);
    $beforeFds = is_dir('/proc/self/fd') ? runwireTlsHandshakeFdCount() : null;

    for ($index = 0; $index < 32; ++$index) {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        \PHPUnit\Framework\Assert::assertIsArray($pair);
        expect($pair)->toHaveCount(2);
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
        expect(runwireTlsHandshakeFdCount() - $beforeFds)->toBeLessThanOrEqual(2);
    }
}
