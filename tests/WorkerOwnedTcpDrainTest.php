<?php

declare(strict_types=1);

use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Network\Connection;
use Infocyph\Runwire\Network\TcpListener;
use Infocyph\Runwire\Supervisor\WorkerContext;

it('aborts live worker-owned accepted TCP sockets on repeated shutdown', function (): void {
    $loop = new SelectLoop();
    $worker = new WorkerContext('tcp-drain', 0, 1, posix_getpid(), posix_getpid());
    $listener = TcpListener::bind('127.0.0.1:0');
    $accepted = [];
    $listener->start($loop, static function (Connection $connection) use ($worker, &$accepted): void {
        $worker->ownConnection($connection);
        $accepted[] = $connection;
    });
    $client = stream_socket_client('tcp://' . $listener->address(), $errno, $error, 1.0);
    expect($client)->toBeResource();

    try {
        $loop->delay(0.03, static function () use ($worker, $listener): void {
            $listener->close();
            $worker->requestStop();
            $worker->requestStop();
            $worker->close();
        });
        $loop->delay(0.1, static fn() => $loop->stop());
        $loop->run();

        expect($accepted)->toHaveCount(1)
            ->and($listener->activeConnections())->toBe(0)
            ->and($loop->diagnostics()->readWatchers)->toBe(0)
            ->and($loop->diagnostics()->writeWatchers)->toBe(0);
        expect(static fn() => $worker->ownConnection($accepted[0]))->toThrow(LogicException::class);
    } finally {
        fclose($client);
        $listener->close();
        $worker->close();
    }
});
