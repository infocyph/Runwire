<?php

declare(strict_types=1);

use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Network\Connection;
use Infocyph\Runwire\Network\DialEndpoint;
use Infocyph\Runwire\Network\Dialer;
use Infocyph\Runwire\Network\DialOptions;
use Infocyph\Runwire\Network\Enum\ConnectionState;

it('establishes a real numeric IPv4 TCP connection without blocking on stream acceptance', function (): void {
    $server = stream_socket_server('tcp://127.0.0.1:0', $code, $message);
    if (!is_resource($server)) {
        throw new RuntimeException($message);
    }
    $address = stream_socket_get_name($server, false);
    expect($address)->toBeString();
    $loop = new SelectLoop();
    $dialer = new Dialer($loop);
    $resolved = null;
    $failed = null;
    $dialer->connect('tcp://' . $address, function ($connection, $error) use (&$resolved, &$failed): void {
        $resolved = $connection;
        $failed = $error;
    });
    $loop->run();
    expect($failed)->toBeNull()
        ->and($resolved)->toBeInstanceOf(Connection::class)
        ->and($resolved->state())->toBe(ConnectionState::OPEN)
        ->and($dialer->pendingCount())->toBe(0);
    $peer = stream_socket_accept($server, 1.0);
    expect($peer)->toBeResource();
    $resolved->abort();
    fclose($peer);
    fclose($server);
    expect($loop->diagnostics()->readWatchers)->toBe(0)
        ->and($loop->diagnostics()->writeWatchers)->toBe(0)
        ->and($loop->diagnostics()->timersActive)->toBe(0);
});

it('connects a real Unix-domain socket and rejects synchronous DNS fallback', function (): void {
    $path = sys_get_temp_dir() . '/runwire-dial-' . bin2hex(random_bytes(8)) . '.sock';
    $listener = stream_socket_server('unix://' . $path, $errno, $message);
    if (!is_resource($listener)) {
        throw new RuntimeException($message);
    }
    try {
        $loop = new SelectLoop();
        $dialer = new Dialer($loop);
        expect(fn() => $dialer->connect('tcp://example.com:80', static function (): void {}))
            ->toThrow(InvalidArgumentException::class);
        $resolved = null;
        $dialer->connect(DialEndpoint::parse('unix://' . $path), function ($connection, $error) use (&$resolved): void {
            expect($error)->toBeNull();
            $resolved = $connection;
        });
        $loop->run();
        expect($resolved)->toBeInstanceOf(Connection::class)
            ->and($dialer->pendingCount())->toBe(0);
        $peer = stream_socket_accept($listener, 1.0);
        expect($peer)->toBeResource();
        $resolved->abort();
        fclose($peer);
    } finally {
        fclose($listener);
        unlink($path);
    }
});

it('rejects over-capacity dials and releases watchers and socket timers on cancellation', function (): void {
    $server = stream_socket_server('tcp://127.0.0.1:0', $code, $message);
    if (!is_resource($server)) {
        throw new RuntimeException($message);
    }
    $address = stream_socket_get_name($server, false);
    expect($address)->toBeString();
    $loop = new SelectLoop();
    $dialer = new Dialer($loop, 1);
    $events = [];
    $id = $dialer->connect('tcp://' . $address, function ($connection, $error) use (&$events): void {
        $events[] = [$connection, $error];
    }, new DialOptions(connectTimeoutSeconds: 1.0));
    expect(fn() => $dialer->connect('tcp://' . $address, static function (): void {}))
        ->toThrow(OverflowException::class);
    expect($dialer->cancel($id))->toBeTrue()
        ->and($dialer->cancel($id))->toBeFalse();
    $loop->run();
    expect($events)->toHaveCount(1)
        ->and($events[0][0])->toBeNull()
        ->and($events[0][1])->toBeInstanceOf(Throwable::class)
        ->and($dialer->pendingCount())->toBe(0)
        ->and($loop->diagnostics()->writeWatchers)->toBe(0)
        ->and($loop->diagnostics()->timersActive)->toBe(0);
    $dialer->close();
    fclose($server);
});
