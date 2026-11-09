<?php

declare(strict_types=1);

require_once __DIR__ . '/Support/SocketFixture.php';


use Infocyph\Runwire\Executor\ProcessExecutor;
use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Network\AsyncDnsResolver;
use Infocyph\Runwire\Network\Connection;
use Infocyph\Runwire\Network\Dialer;
use Infocyph\Runwire\Network\HappyEyeballsDialer;

it('falls back from a failing IPv6 candidate to a real IPv4 listener without leaking sockets', function (): void {
    $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $message);
    if (!is_resource($server)) {
        throw \Infocyph\Runwire\Tests\Support\socketFailure($message, 'Socket operation failed.');
    }
    $address = stream_socket_get_name($server, false);
    if (!is_string($address) || ($separator = strrpos($address, ':')) === false) {
        throw new RuntimeException('Happy Eyeballs test listener did not return a TCP port.');
    }
    $port = (int) substr($address, $separator + 1);
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
    \PHPUnit\Framework\Assert::assertInstanceOf(Connection::class, $result);
    expect($error)->toBeNull()
        ->and($racer->pendingCount())->toBe(0)
        ->and($dialer->pendingCount())->toBe(0);
    $peer = stream_socket_accept($server, 1.0);
    \PHPUnit\Framework\Assert::assertIsResource($peer);
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
        throw \Infocyph\Runwire\Tests\Support\socketFailure($message, 'Socket operation failed.');
    }
    $address = stream_socket_get_name($server, false);
    if (!is_string($address) || ($separator = strrpos($address, ':')) === false) {
        throw new RuntimeException('Happy Eyeballs test listener did not return a TCP port.');
    }
    $port = (int) substr($address, $separator + 1);
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
    \PHPUnit\Framework\Assert::assertInstanceOf(Connection::class, $result);
    expect($error)->toBeNull()
        ->and($racer->pendingCount())->toBe(0);
    $peer = stream_socket_accept($server, 1.0);
    \PHPUnit\Framework\Assert::assertIsResource($peer);
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
