<?php

declare(strict_types=1);

use Infocyph\Runwire\CancellationSource;
use Infocyph\Runwire\Exception\CancelledException;
use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Network\ConnectionFactory;
use Infocyph\Runwire\Network\Proxy\Socks5Handshake;
use Infocyph\Runwire\Network\Proxy\Socks5Tunnel;
use Infocyph\Runwire\Runtime\Enum\CancellationReason;

it('negotiates live nonblocking SOCKS5 and preserves pipelined tunnel bytes', function (): void {
    [$socket, $peer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    $loop = new SelectLoop();
    $connection = ConnectionFactory::accepted($loop, $socket);
    $captured = [];
    $data = '';
    $completion = [];
    $tunnel = new Socks5Tunnel(
        $loop, $connection, new Socks5Handshake(remoteDns: true), 'example.com', 443,
        function ($ready, ?Throwable $error) use (&$completion, &$data, $loop): void {
            $completion[] = [$ready, $error];
            if ($ready !== null) {
                $ready->onData(function ($connection) use (&$data, $loop): void {
                    $data .= $connection->read();
                    $loop->stop();
                });
            } else {
                $loop->stop();
            }
        },
    );
    try {
        $tunnel->start();
        $loop->delay(0.01, static function () use ($peer, &$captured): void {
            $captured[] = fread($peer, 1024);
            fwrite($peer, "\x05\x00");
        });
        $loop->delay(0.02, static function () use ($peer, &$captured): void {
            $captured[] = fread($peer, 1024);
            fwrite($peer, "\x05\x00\x00\x01\x7f\x00\x00\x01\x01\xbb" . 'app-data');
        });
        $loop->delay(1, static function () use ($loop): void {
            $loop->stop();
        });
        $loop->run();
        expect($captured[0])->toBe("\x05\x01\x00")
            ->and($captured[1])->toBe("\x05\x01\x00\x03\x0bexample.com\x01\xbb")
            ->and($completion)->toHaveCount(1)
            ->and($completion[0][0])->toBe($connection)
            ->and($completion[0][1])->toBeNull()
            ->and($data)->toBe('app-data');
    } finally {
        $connection->abort();
        fclose($peer);
    }
});

it('refuses SOCKS5 DNS-leaking target and credentials before transmitting', function (): void {
    [$socket, $peer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    $loop = new SelectLoop();
    $connection = ConnectionFactory::accepted($loop, $socket);
    try {
        $local = new Socks5Tunnel(
            $loop, $connection, new Socks5Handshake(), 'remote.example', 443,
            static function (): void {},
        );
        expect(fn() => $local->start())->toThrow(InvalidArgumentException::class, 'pre-resolved');
        $auth = new Socks5Tunnel(
            $loop, $connection,
            new Socks5Handshake(username: 'me', password: 'key', verifiedProxyTransport: true),
            '127.0.0.1', 443, static function (): void {},
        );
        expect(fn() => $auth->start())->toThrow(LogicException::class, 'verified proxy TLS');
        stream_set_blocking($peer, false);
        expect(fread($peer, 16))->toBe('');
    } finally {
        $connection->abort();
        fclose($peer);
    }
});

it('cancels SOCKS5 setup exactly once without leaving a live transport observer', function (): void {
    [$socket, $peer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    $loop = new SelectLoop();
    $connection = ConnectionFactory::accepted($loop, $socket);
    $source = new CancellationSource();
    $results = [];
    $tunnel = new Socks5Tunnel(
        $loop, $connection, new Socks5Handshake(), '127.0.0.1', 443,
        static function ($ready, ?Throwable $error) use (&$results): void {
            $results[] = [$ready, $error];
        },
        token: $source->token(),
    );
    try {
        $tunnel->start();
        $source->cancel(CancellationReason::HOST_CANCELLED);
        expect($results)->toHaveCount(1)
            ->and($results[0][0])->toBeNull()
            ->and($results[0][1])->toBeInstanceOf(CancelledException::class)
            ->and($source->token()->subscriptionCount())->toBe(0)
            ->and($tunnel->cancel())->toBeFalse()
            ->and($loop->diagnostics()->readWatchers)->toBe(0)
            ->and($loop->diagnostics()->writeWatchers)->toBe(0)
            ->and($loop->diagnostics()->timersActive)->toBe(0);
    } finally {
        $source->dispose();
        $connection->abort();
        fclose($peer);
    }
});
