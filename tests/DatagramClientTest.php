<?php

declare(strict_types=1);

use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Network\Datagram;
use Infocyph\Runwire\Network\DatagramClient;
use Infocyph\Runwire\Network\DatagramListener;
use Infocyph\Runwire\Network\DatagramOptions;
use Infocyph\Runwire\Network\Enum\DatagramWriteState;

it('round trips real connected IPv4 UDP through the same inbound datagram engine', function (): void {
    $loop = new SelectLoop();
    $server = DatagramListener::bind('127.0.0.1:0');
    $client = DatagramClient::connect('udp://' . $server->address());
    $response = null;

    $server->start($loop, static function (Datagram $packet, DatagramListener $listener): void {
        expect($packet->payload)->toBe('ping');
        expect($listener->sendTo('pong', $packet->peerAddress)->sent())->toBeTrue();
    });
    $client->start($loop, static function (Datagram $packet) use (&$response, $loop): void {
        $response = $packet;
        $loop->stop();
    });
    expect($client->connectedPeer())->toBe('udp://' . $server->address())
        ->and($client->send('ping')->state)->toBe(DatagramWriteState::SENT);
    $timeout = $loop->delay(1.0, static fn () => $loop->stop());
    $loop->run();
    $loop->cancel($timeout);
    $client->close();
    $server->close();
    expect($response)->toBeInstanceOf(Datagram::class)
        ->and($response->payload)->toBe('pong')
        ->and($response->peerAddress)->toBe($server->address())
        ->and($loop->diagnostics()->readWatchers)->toBe(0);
});

it('reuses the server datagram engine for unconnected client sendTo and metadata', function (): void {
    $loop = new SelectLoop();
    $server = DatagramListener::bind('127.0.0.1:0');
    $client = DatagramClient::bind('127.0.0.1:0');
    $response = null;

    $server->start($loop, static function (Datagram $packet, DatagramListener $socket): void {
        $socket->sendTo('reply', $packet->peerAddress);
    });
    $client->start($loop, static function (Datagram $packet) use (&$response, $loop): void {
        $response = $packet;
        $loop->stop();
    });
    expect($client->connectedPeer())->toBeNull()
        ->and($client->sendTo('hello', $server->address())->sent())->toBeTrue();
    $timeout = $loop->delay(1.0, static fn () => $loop->stop());
    $loop->run();
    $loop->cancel($timeout);
    $client->close();
    $server->close();
    expect($response?->payload)->toBe('reply')
        ->and($response?->peerAddress)->toBe($server->address())
        ->and($loop->diagnostics()->readWatchers)->toBe(0);
});

it('delivers empty UDP packets and rejects oversized datagrams without merging message boundaries', function (): void {
    $loop = new SelectLoop();
    $socket = DatagramListener::bind('127.0.0.1:0', new DatagramOptions(maxDatagramBytes: 4));
    $payloads = [];
    $socket->start($loop, static function (Datagram $packet) use (&$payloads, $loop): void {
        $payloads[] = $packet->payload;
        if (count($payloads) === 2) {
            $loop->stop();
        }
    });
    $client = stream_socket_client('udp://' . $socket->address(), $errno, $error, 0.0);
    expect($client)->toBeResource();
    expect(stream_socket_sendto($client, ''))->toBe(0)
        ->and(stream_socket_sendto($client, 'oversize'))->toBe(8)
        ->and(stream_socket_sendto($client, 'ok'))->toBe(2);
    $timeout = $loop->delay(1.0, static fn () => $loop->stop());
    $loop->run();
    $loop->cancel($timeout);
    $socket->close();
    fclose($client);
    expect($payloads)->toBe(['', 'ok'])
        ->and($socket->receivedDatagrams())->toBe(2)
        ->and($socket->rejectedDatagrams())->toBe(1)
        ->and($loop->diagnostics()->readWatchers)->toBe(0);
});

it('rejects hostnames for UDP without synchronous DNS or any created socket', function (): void {
    expect(fn() => DatagramClient::connect('udp://example.com:443'))
        ->toThrow(Infocyph\Runwire\Exception\ListenerException::class);
});


it('round trips connected UDP over IPv6 when the local kernel supports it', function (): void {
    $probe = stream_socket_server('udp://[::1]:0', $errno, $message, STREAM_SERVER_BIND);
    if (!is_resource($probe)) {
        expect(filter_var('::1', FILTER_VALIDATE_IP, FILTER_FLAG_IPV6))->toBe('::1');

        return;
    }
    fclose($probe);

    $loop = new SelectLoop();
    $server = DatagramListener::bind('[::1]:0');
    $client = DatagramClient::connect('udp://' . $server->address());
    $reply = null;
    $server->start($loop, static function (Datagram $packet, DatagramListener $listener): void {
        expect($packet->payload)->toBe('ipv6');
        expect($listener->sendTo('done', $packet->peerAddress)->sent())->toBeTrue();
    });
    $client->start($loop, static function (Datagram $packet) use (&$reply, $loop): void {
        $reply = $packet->payload;
        $loop->stop();
    });
    expect($client->send('ipv6')->sent())->toBeTrue();
    $timeout = $loop->delay(1.0, static fn () => $loop->stop());
    $loop->run();
    $loop->cancel($timeout);
    $client->close();
    $server->close();

    expect($reply)->toBe('done')
        ->and($loop->diagnostics()->readWatchers)->toBe(0)
        ->and($loop->diagnostics()->timersActive)->toBe(0);
});
