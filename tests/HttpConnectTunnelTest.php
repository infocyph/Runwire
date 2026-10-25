<?php

declare(strict_types=1);

use Infocyph\Runwire\CancellationSource;
use Infocyph\Runwire\Exception\CancelledException;
use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Network\ConnectionFactory;
use Infocyph\Runwire\Network\Proxy\HttpConnectHandshake;
use Infocyph\Runwire\Network\Proxy\HttpConnectTunnel;

it('establishes a bounded real nonblocking CONNECT and preserves the first tunnel bytes', function (): void {
    [$stream, $peer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    $loop = new SelectLoop();
    $connection = ConnectionFactory::accepted($loop, $stream);
    $request = '';
    $remainder = '';
    $results = [];
    $tunnel = new HttpConnectTunnel(
        $loop,
        $connection,
        new HttpConnectHandshake(),
        'target.example',
        8080,
        function ($ready, ?Throwable $error) use (&$results, &$remainder, $loop): void {
            $results[] = [$ready, $error];
            if ($ready !== null) {
                $ready->onData(function ($connection) use (&$remainder, $loop): void {
                    $remainder .= $connection->read();
                    $loop->stop();
                });
            } else {
                $loop->stop();
            }
        },
    );
    try {
        $tunnel->start();
        $loop->delay(0.02, static function () use ($peer, &$request): void {
            $request = fread($peer, 1024);
            fwrite($peer, "HTTP/1.1 200 Connection Established\r\nX-Proxy: yes\r\n\r\n" . 'target-hello');
        });
        $loop->delay(1, static function () use ($loop): void {
            $loop->stop();
        });
        $loop->run();
        expect($request)->toBe("CONNECT target.example:8080 HTTP/1.1\r\nHost: target.example:8080\r\n\r\n")
            ->and($results)->toHaveCount(1)
            ->and($results[0][0])->toBe($connection)
            ->and($results[0][1])->toBeNull()
            ->and($remainder)->toBe('target-hello')
            ->and($tunnel->cancel())->toBeFalse();
    } finally {
        $connection->abort();
        fclose($peer);
    }
});

it('aborts a failed CONNECT response without handing out the connection', function (): void {
    [$stream, $peer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    $loop = new SelectLoop();
    $connection = ConnectionFactory::accepted($loop, $stream);
    $results = [];
    $tunnel = new HttpConnectTunnel(
        $loop, $connection, new HttpConnectHandshake(), 'target.example', 443,
        static function ($ready, ?Throwable $error) use (&$results, $loop): void {
            $results[] = [$ready, $error];
            $loop->stop();
        },
    );
    try {
        $tunnel->start();
        $loop->delay(0.01, static function () use ($peer): void {
            fwrite($peer, "HTTP/1.1 407 Proxy Authentication Required\r\n\r\n");
        });
        $loop->delay(1, static function () use ($loop): void {
            $loop->stop();
        });
        $loop->run();
        expect($results)->toHaveCount(1)
            ->and($results[0][0])->toBeNull()
            ->and($results[0][1])->toBeInstanceOf(RuntimeException::class)
            ->and($tunnel->cancel())->toBeFalse();
    } finally {
        $connection->abort();
        fclose($peer);
    }
});

it('cancels a pending CONNECT once and releases all callback owners', function (): void {
    [$stream, $peer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    $loop = new SelectLoop();
    $connection = ConnectionFactory::accepted($loop, $stream);
    $source = new CancellationSource();
    $results = [];
    $tunnel = new HttpConnectTunnel(
        $loop, $connection, new HttpConnectHandshake(), 'target.example', 443,
        static function ($ready, ?Throwable $error) use (&$results): void {
            $results[] = [$ready, $error];
        },
        token: $source->token(),
    );
    try {
        $tunnel->start();
        $source->cancel(\Infocyph\Runwire\Runtime\Enum\CancellationReason::HOST_CANCELLED);
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
