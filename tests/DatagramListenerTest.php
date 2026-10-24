<?php

declare(strict_types=1);

use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Network\Datagram;
use Infocyph\Runwire\Network\DatagramListener;
use Infocyph\Runwire\Network\DatagramOptions;
use Infocyph\Runwire\Network\Enum\DatagramWriteState;

it('receives and replies to UDP datagrams', function (): void {
    $listener = DatagramListener::bind('127.0.0.1:0');
    $loop = new SelectLoop();
    $received = null;

    $listener->start($loop, static function (Datagram $datagram, DatagramListener $listener) use (&$received, $loop): void {
        $received = $datagram;
        expect($listener->sendTo('pong', $datagram->peerAddress)->sent())->toBeTrue();
        $loop->defer(static fn () => $loop->stop());
    });

    $client = stream_socket_client('udp://' . $listener->address(), $errno, $error, 1.0);
    if (!is_resource($client)) {
        throw new RuntimeException(sprintf('Unable to create UDP client: %s (%d).', $error, $errno));
    }
    fwrite($client, 'ping');
    $loop->delay(0.5, static fn () => $loop->stop());
    $loop->run();
    stream_set_timeout($client, 1);
    $reply = fread($client, 4);
    fclose($client);
    $listener->close();

    expect($received)->toBeInstanceOf(Datagram::class)
        ->and($received?->payload)->toBe('ping')
        ->and($reply)->toBe('pong');
});

it('rejects outbound UDP payloads above the configured ceiling before send', function (): void {
    $listener = DatagramListener::bind('127.0.0.1:0', new DatagramOptions(maxDatagramBytes: 4));
    $result = $listener->sendTo('12345', '127.0.0.1:9');
    $listener->close();

    expect($result->state)->toBe(DatagramWriteState::REJECTED_LIMIT);
});


it('allows a receive callback to close the listener without reusing the closed resource', function (): void {
    $listener = DatagramListener::bind('127.0.0.1:0', new DatagramOptions(receiveBatchSize: 2));
    $loop = new SelectLoop();
    $received = 0;

    $listener->start($loop, static function (Datagram $datagram, DatagramListener $listener) use (&$received, $loop): void {
        expect($datagram->payload)->toBe('close');
        ++$received;
        $listener->close();
        $loop->stop();
    });

    $client = stream_socket_client('udp://' . $listener->address(), $errno, $error, 1.0);
    if (!is_resource($client)) {
        throw new RuntimeException(sprintf('Unable to create UDP client: %s (%d).', $error, $errno));
    }

    fwrite($client, 'close');
    $loop->delay(0.5, static fn () => $loop->stop());
    $loop->run();
    fclose($client);

    expect($received)->toBe(1)
        ->and($listener->isClosed())->toBeTrue();
});
