<?php

declare(strict_types=1);

use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Network\Datagram;
use Infocyph\Runwire\Network\DatagramListener;
use Infocyph\Runwire\Network\DatagramOptions;

function abuseUdpClient(string $address)
{
    $errno = 0;
    $error = '';
    $client = stream_socket_client('udp://' . $address, $errno, $error, 1.0);
    if (!is_resource($client)) {
        throw new RuntimeException(sprintf('Unable to create UDP abuse client: %s (%d).', $error, $errno));
    }

    return $client;
}

it('rejects oversized inbound datagrams without dispatching application work', function (): void {
    $listener = DatagramListener::bind('127.0.0.1:0', new DatagramOptions(maxDatagramBytes: 4));
    $loop = new SelectLoop();
    $received = [];
    $listener->start($loop, static function (Datagram $datagram) use (&$received): void {
        $received[] = $datagram->payload;
    });

    $client = abuseUdpClient($listener->address());
    fwrite($client, '12345');
    fwrite($client, 'ok');
    $loop->delay(0.05, static fn() => $loop->stop());
    $loop->run();
    fclose($client);

    expect($received)->toBe(['ok'])
        ->and($listener->receivedDatagrams())->toBe(1)
        ->and($listener->rejectedDatagrams())->toBeGreaterThanOrEqual(1);

    $listener->close();
});

it('bounds receive work per event-loop callback during bursts', function (): void {
    $listener = DatagramListener::bind('127.0.0.1:0', new DatagramOptions(
        maxDatagramBytes: 64,
        receiveBatchSize: 2,
    ));
    $loop = new SelectLoop();
    $received = 0;
    $listener->start($loop, static function () use (&$received, $loop): void {
        ++$received;
        if ($received === 6) {
            $loop->stop();
        }
    });

    $client = abuseUdpClient($listener->address());
    for ($i = 0; $i < 6; ++$i) {
        fwrite($client, 'd' . $i);
    }
    $loop->delay(0.2, static fn() => $loop->stop());
    $loop->run();
    fclose($client);

    expect($received)->toBe(6)
        ->and($listener->receivedDatagrams())->toBe(6);

    $listener->close();
});

it('closes a datagram listener when an application callback fails', function (): void {
    $listener = DatagramListener::bind('127.0.0.1:0');
    $loop = new SelectLoop();
    $listener->start($loop, static function (): void {
        throw new RuntimeException('intentional datagram callback failure');
    });

    $client = abuseUdpClient($listener->address());
    fwrite($client, 'boom');

    expect(fn() => $loop->run())
        ->toThrow(RuntimeException::class, 'intentional datagram callback failure');
    expect($listener->isClosed())->toBeTrue();

    fclose($client);
});
