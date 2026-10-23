<?php

declare(strict_types=1);

use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Network\Connection;
use Infocyph\Runwire\Network\Enum\CloseReason;
use Infocyph\Runwire\Network\Enum\ConnectionState;

it('never treats an unverified outbound TLS EOF as a clean application EOF', function (): void {
    $streams = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    expect($streams)->toBeArray()->toHaveCount(2);
    $loop = new SelectLoop();
    $connection = new Connection($loop, $streams[0], encrypted: true, strictTlsPeerEof: true);
    $received = '';
    $eofs = 0;
    $closed = [];
    $connection->onData(function (Connection $conn) use (&$received): void {
        $received .= $conn->read();
    });
    $connection->onEof(function () use (&$eofs): void {
        ++$eofs;
    });
    $connection->onClose(function (Connection $conn, CloseReason $reason) use (&$closed, $loop): void {
        $closed[] = $reason;
        $loop->stop();
    });
    fwrite($streams[1], 'fragment');
    fclose($streams[1]);
    $timer = $loop->delay(1.0, static function () use ($loop): void {
        $loop->stop();
    });
    $loop->run();
    $loop->cancel($timer);
    expect($received)->toBe('fragment')
        ->and($eofs)->toBe(0)
        ->and($closed)->toBe([CloseReason::TLS_UNVERIFIED_EOF])
        ->and($connection->state())->toBe(ConnectionState::CLOSED)
        ->and($loop->diagnostics()->readWatchers)->toBe(0)
        ->and($loop->diagnostics()->writeWatchers)->toBe(0)
        ->and($loop->diagnostics()->timersActive)->toBe(0);
});

it('retains plain TCP EOF and prohibits raw TLS write half-close', function (): void {
    $streams = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    expect($streams)->toBeArray()->toHaveCount(2);
    $loop = new SelectLoop();
    $tls = new Connection($loop, $streams[0], encrypted: true, strictTlsPeerEof: true);
    expect(fn() => $tls->shutdownWrite())->toThrow(LogicException::class)
        ->and($tls->state())->toBe(ConnectionState::OPEN);
    $tls->abort();
    fclose($streams[1]);
    $plain = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    expect($plain)->toBeArray()->toHaveCount(2);
    $connection = new Connection($loop, $plain[0]);
    $reasons = [];
    $connection->onClose(function (Connection $conn, CloseReason $reason) use (&$reasons, $loop): void {
        $reasons[] = $reason;
        $loop->stop();
    });
    fclose($plain[1]);
    $timer = $loop->delay(1.0, static function () use ($loop): void {
        $loop->stop();
    });
    $loop->run();
    $loop->cancel($timer);
    expect($reasons)->toBe([CloseReason::PEER_CLOSED])
        ->and($loop->diagnostics()->readWatchers)->toBe(0)
        ->and($loop->diagnostics()->timersActive)->toBe(0);
});
