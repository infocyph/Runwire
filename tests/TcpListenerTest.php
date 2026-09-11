<?php

declare(strict_types=1);

use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Network\Connection;
use Infocyph\Runwire\Network\ListenerOptions;
use Infocyph\Runwire\Network\TcpListener;

it('accepts TCP connections and exposes peer/local metadata', function (): void {
    $loop = new SelectLoop();
    $listener = TcpListener::bind('127.0.0.1:0');
    $peerAddress = null;
    $localAddress = null;
    $listener->start($loop, static function (Connection $connection) use (&$peerAddress, &$localAddress): void {
        $peerAddress = $connection->peerAddress();
        $localAddress = $connection->localAddress();
        $connection->onData(static function (Connection $connection): void {
            $connection->write('echo:' . $connection->read());
            $connection->closeGracefully();
        });
    });

    $client = stream_socket_client('tcp://' . $listener->address(), $errno, $error, 1.0);
    expect($client)->toBeResource();
    fwrite($client, 'hello');
    $loop->delay(1.0, static fn () => $loop->stop());
    $loop->run();

    expect(stream_get_contents($client))->toBe('echo:hello')
        ->and($listener->activeConnections())->toBe(0)
        ->and($listener->acceptedConnections())->toBe(1)
        ->and($peerAddress)->toBeString()
        ->and($localAddress)->toBe($listener->address());

    fclose($client);
    $listener->close();
});

it('pauses at the connection ceiling and resumes when capacity returns', function (): void {
    $loop = new SelectLoop();
    $listener = TcpListener::bind('127.0.0.1:0', new ListenerOptions(maxConnections: 1, acceptBatchSize: 4));
    $accepted = [];
    $listener->start($loop, static function (Connection $connection) use (&$accepted): void {
        $accepted[] = $connection;
        $connection->onData(static fn (Connection $connection): string => $connection->read());
    });

    $client1 = stream_socket_client('tcp://' . $listener->address(), $errno1, $error1, 1.0);
    $client2 = stream_socket_client('tcp://' . $listener->address(), $errno2, $error2, 1.0);
    expect($client1)->toBeResource()->and($client2)->toBeResource();

    $loop->delay(0.03, static function () use (&$accepted): void {
        if (isset($accepted[0])) { $accepted[0]->abort(); }
    });
    $loop->delay(0.08, static fn () => $loop->stop());
    $loop->run();

    expect($accepted)->toHaveCount(2)->and($listener->activeConnections())->toBe(1);
    $accepted[1]->abort();
    fclose($client1);
    fclose($client2);
    $listener->close();
});
