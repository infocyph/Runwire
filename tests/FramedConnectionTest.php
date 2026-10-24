<?php

declare(strict_types=1);

use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Network\Connection;
use Infocyph\Runwire\Network\Enum\CloseReason;
use Infocyph\Runwire\Network\Enum\ConnectionState;
use Infocyph\Runwire\Protocol\FramedConnection;
use Infocyph\Runwire\Protocol\LineCodec;

it('dispatches framed input and serializes framed output', function (): void {
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    if (!is_array($pair)) {
        throw new RuntimeException('Unable to create socket pair.');
    }
    [$server, $client] = $pair;
    $loop = new SelectLoop();
    $transport = new Connection($loop, $server);
    $frames = [];

    new FramedConnection(
        $loop,
        $transport,
        new LineCodec(),
        static function (string $frame, FramedConnection $connection) use (&$frames): void {
            $frames[] = $frame;
            $connection->send(strtoupper($frame));
            $connection->closeGracefully();
        },
    );

    fwrite($client, "hello\n");
    $loop->delay(0.2, static fn () => $loop->stop());
    $loop->run();
    stream_set_blocking($client, false);
    $response = stream_get_contents($client);
    fclose($client);

    expect($frames)->toBe(['hello'])->and($response)->toBe("HELLO\n");
});

it('spreads queued frames across deferred batches', function (): void {
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    if (!is_array($pair)) {
        throw new RuntimeException('Unable to create socket pair.');
    }
    [$server, $client] = $pair;
    $loop = new SelectLoop();
    $transport = new Connection($loop, $server);
    $frames = [];

    new FramedConnection(
        $loop,
        $transport,
        new LineCodec(),
        static function (string $frame) use (&$frames, $loop): void {
            $frames[] = $frame;
            if (count($frames) === 5) {
                $loop->stop();
            }
        },
        2,
    );

    fwrite($client, "1\n2\n3\n4\n5\n");
    $loop->delay(0.2, static fn () => $loop->stop());
    $loop->run();
    fclose($client);

    expect($frames)->toBe(['1', '2', '3', '4', '5']);
});

it('classifies codec failures as protocol errors', function (): void {
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    if (!is_array($pair)) {
        throw new RuntimeException('Unable to create socket pair.');
    }
    [$server, $client] = $pair;
    $loop = new SelectLoop();
    $transport = new Connection($loop, $server);

    new FramedConnection($loop, $transport, new LineCodec("\n", 3), static function (): void {});
    fwrite($client, 'oversized');

    try {
        $loop->delay(0.2, static fn () => $loop->stop());
        $loop->run();
    } catch (Throwable) {
        // The protocol exception is allowed to reach the loop after deterministic transport cleanup.
    }
    fclose($client);

    expect($transport->state())->toBe(ConnectionState::CLOSED)
        ->and($transport->closeReason())->toBe(CloseReason::PROTOCOL_ERROR);
});
