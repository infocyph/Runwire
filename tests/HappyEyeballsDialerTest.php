<?php

declare(strict_types=1);

use Infocyph\Runwire\Executor\ProcessExecutor;
use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Network\AsyncDnsResolver;
use Infocyph\Runwire\Network\Connection;
use Infocyph\Runwire\Network\Dialer;
use Infocyph\Runwire\Network\HappyEyeballsDialer;

it('falls back from a failing IPv6 candidate to a real IPv4 listener without leaking sockets', function (): void {
    $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $message);
    if (!is_resource($server)) {
        throw new RuntimeException($message);
    }
    $address = stream_socket_get_name($server, false);
    $port = (int) substr((string) $address, strrpos((string) $address, ':') + 1);
    $loop = new SelectLoop();
    $executor = new ProcessExecutor($loop, 1, 4);
    $dialer = new Dialer($loop);
    $racer = new HappyEyeballsDialer($loop, $dialer, new AsyncDnsResolver($executor));
    $result = null;
    $error = null;
    $racer->connectResolved(['::1', '127.0.0.1'], $port, function ($connection, $failure) use (&$result, &$error, $loop): void {
        $result = $connection;
        $error = $failure;
        $loop->stop();
    });
    $loop->run();
    expect($error)->toBeNull()
        ->and($result)->toBeInstanceOf(Connection::class)
        ->and($racer->pendingCount())->toBe(0)
        ->and($dialer->pendingCount())->toBe(0);
    $peer = stream_socket_accept($server, 1.0);
    expect($peer)->toBeResource();
    $result->abort();
    fclose($peer);
    fclose($server);
    $executor->close();
    expect($loop->diagnostics()->readWatchers)->toBe(0)
        ->and($loop->diagnostics()->writeWatchers)->toBe(0)
        ->and($loop->diagnostics()->timersActive)->toBe(0);
});

it('resolves localhost off-loop and hands the winner to the same numeric connector', function (): void {
    $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $message);
    if (!is_resource($server)) {
        throw new RuntimeException($message);
    }
    $address = stream_socket_get_name($server, false);
    $port = (int) substr((string) $address, strrpos((string) $address, ':') + 1);
    $loop = new SelectLoop();
    $executor = new ProcessExecutor($loop, 1, 4);
    $dialer = new Dialer($loop);
    $racer = new HappyEyeballsDialer($loop, $dialer, new AsyncDnsResolver($executor));
    $result = null;
    $error = null;
    $racer->connect('tcp://localhost:' . $port, function ($connection, $failure) use (&$result, &$error, $loop): void {
        $result = $connection;
        $error = $failure;
        $loop->stop();
    });
    $loop->run();
    expect($error)->toBeNull()
        ->and($result)->toBeInstanceOf(Connection::class)
        ->and($racer->pendingCount())->toBe(0);
    $peer = stream_socket_accept($server, 1.0);
    expect($peer)->toBeResource();
    $result->abort();
    fclose($peer);
    fclose($server);
    $executor->close();
    expect($loop->diagnostics()->readWatchers)->toBe(0);
});


it('rejects invalid pre-resolved candidates before reserving race capacity', function (): void {
    $loop = new SelectLoop();
    $executor = new ProcessExecutor($loop, 1, 2);
    $racer = new HappyEyeballsDialer($loop, new Dialer($loop), new AsyncDnsResolver($executor));
    expect(fn() => $racer->connectResolved(['not-an-ip'], 443, static function (): void {}))
        ->toThrow(InvalidArgumentException::class)
        ->and($racer->pendingCount())->toBe(0)
        ->and($loop->diagnostics()->timersActive)->toBe(0);
    $executor->close();
});
