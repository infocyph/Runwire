<?php

declare(strict_types=1);

use Infocyph\Runwire\CancellationSource;
use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Network\Connection;
use Infocyph\Runwire\Network\ConnectionFactory;
use Infocyph\Runwire\Network\Internal\ByteBudget;
use Infocyph\Runwire\Network\TcpListener;
use Infocyph\Runwire\Runtime\Enum\CancellationReason;

it('drains 128 simultaneous socket pairs without abandoned watchers, owner tokens or queued bytes', function (): void {
    $before = is_dir('/proc/self/fd') ? count(glob('/proc/self/fd/*') ?: []) : null;
    $loop = new SelectLoop();
    $source = new CancellationSource();
    $budget = new ByteBudget(16_384);
    $peers = [];
    $connections = [];
    $closed = 0;
    $delivered = 0;
    $owned = 0;
    for ($i = 0; $i < 128; ++$i) {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        expect($pair)->toBeArray();
        [$stream, $peer] = $pair;
        $connection = ConnectionFactory::accepted($loop, $stream, bufferBudget: $budget);
        $connection->onClose(function () use (&$closed): void {
            ++$closed;
        });
        $connection->onData(function (Connection $active) use (&$delivered): void {
            if ($active->read() !== '') {
                ++$delivered;
            }
            $active->closeGracefully();
        });
        if ($i % 8 === 0) {
            $connection->bindOwner($source->token());
            ++$owned;
        } else {
            expect(fwrite($peer, 'payload'))->toBe(7);
            if ($i % 4 === 1) {
                expect(stream_socket_shutdown($peer, STREAM_SHUT_WR))->toBeTrue();
            }
        }
        $peers[] = $peer;
        $connections[] = $connection;
    }
    expect($source->token()->subscriptionCount())->toBe($owned);
    $source->cancel(CancellationReason::HOST_CANCELLED);
    $loop->delay(0.15, function () use ($loop): void {
        $loop->stop();
    });
    $loop->run();

    expect($closed)->toBe(128)
        ->and($delivered)->toBe(128 - $owned)
        ->and($source->token()->subscriptionCount())->toBe(0)
        ->and($budget->used())->toBe(0)
        ->and($loop->diagnostics()->readWatchers)->toBe(0)
        ->and($loop->diagnostics()->writeWatchers)->toBe(0)
        ->and($loop->diagnostics()->timersActive)->toBe(0);
    foreach ($peers as $peer) {
        fclose($peer);
    }
    $source->dispose();
    foreach ($connections as $connection) {
        $connection->abort();
    }
    expect($closed)->toBe(128);
    if ($before !== null) {
        expect(count(glob('/proc/self/fd/*') ?: []))->toBeLessThanOrEqual($before + 3);
    }
});

it('stops a real TCP acceptor while dozens of sockets are active without leaving watchers', function (): void {
    $loop = new SelectLoop();
    $listener = TcpListener::bind('127.0.0.1:0');
    $accepted = 0;
    $closed = 0;
    $clients = [];
    $listener->start($loop, function (Connection $connection) use (&$accepted, &$closed): void {
        ++$accepted;
        $connection->onData(function (Connection $active): void {
            $active->read();
        });
        $connection->onClose(function () use (&$closed): void {
            ++$closed;
        });
    });
    for ($i = 0; $i < 24; ++$i) {
        $stream = stream_socket_client('tcp://' . $listener->address(), $errno, $error, 1.0);
        if (!is_resource($stream)) {
            throw new RuntimeException($error);
        }
        fwrite($stream, 'hello');
        $clients[] = $stream;
    }
    $loop->delay(0.05, function () use ($listener, $loop): void {
        $listener->close();
        $listener->abortConnections();
        $loop->stop();
    });
    $loop->run();

    expect($accepted)->toBe(24)
        ->and($closed)->toBe(24)
        ->and($listener->activeConnections())->toBe(0)
        ->and($listener->pendingHandshakes())->toBe(0)
        ->and($loop->diagnostics()->readWatchers)->toBe(0)
        ->and($loop->diagnostics()->writeWatchers)->toBe(0);
    foreach ($clients as $client) {
        fclose($client);
    }
});
