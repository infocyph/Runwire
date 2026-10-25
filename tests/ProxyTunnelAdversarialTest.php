<?php

declare(strict_types=1);

use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Network\Connection;
use Infocyph\Runwire\Network\ConnectionFactory;
use Infocyph\Runwire\Network\Proxy\HttpConnectHandshake;
use Infocyph\Runwire\Network\Proxy\HttpConnectTunnel;
use Infocyph\Runwire\Network\Proxy\Socks5Handshake;
use Infocyph\Runwire\Network\Proxy\Socks5Tunnel;

/**
 * Exercise the real connection callback/ownership path, not only codec methods.
 * Every negative result must have one error, no successful handoff and no leak.
 */
function runwireProxyAdversarialReply(string $protocol, string $badReply, bool $afterMethod = false): void
{
    $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    expect($sockets)->toBeArray();
    [$socket, $peer] = $sockets;
    $loop = new SelectLoop();
    $connection = ConnectionFactory::accepted($loop, $socket);
    $errors = [];
    $successes = 0;
    $timer = null;
    $methodTimer = null;
    $watchdog = null;
    $complete = static function (?Connection $ready, ?Throwable $error) use (
        &$errors,
        &$successes,
        $loop,
    ): void {
        if ($error !== null) {
            $errors[] = $error;
        } elseif ($ready !== null) {
            ++$successes;
        } else {
            $errors[] = new RuntimeException('A proxy returned an invalid empty success result.');
        }
        $loop->stop();
    };
    $tunnel = $protocol === 'http'
        ? new HttpConnectTunnel($loop, $connection, new HttpConnectHandshake(), 'example.com', 443, $complete)
        : new Socks5Tunnel($loop, $connection, new Socks5Handshake(), '127.0.0.1', 443, $complete);
    try {
        $tunnel->start();
        if ($afterMethod) {
            $methodTimer = $loop->delay(0.01, static function () use ($peer): void {
                fwrite($peer, "\x05\x00");
            });
        }
        $timer = $loop->delay($afterMethod ? 0.03 : 0.01, static function () use ($peer, $badReply): void {
            fwrite($peer, $badReply);
        });
        $watchdog = $loop->delay(0.5, static function () use ($loop): void {
            $loop->stop();
        });
        $loop->run();
        expect($errors)->toHaveCount(1)
            ->and($errors[0])->toBeInstanceOf(RuntimeException::class)
            ->and($successes)->toBe(0)
            ->and($tunnel->cancel())->toBeFalse();
    } finally {
        if ($timer !== null) {
            $loop->cancel($timer);
        }
        if ($methodTimer !== null) {
            $loop->cancel($methodTimer);
        }
        if ($watchdog !== null) {
            $loop->cancel($watchdog);
        }
        $connection->abort();
        fclose($peer);
    }
    expect($loop->diagnostics()->readWatchers)->toBe(0)
        ->and($loop->diagnostics()->writeWatchers)->toBe(0)
        ->and($loop->diagnostics()->timersActive)->toBe(0);
}

it('refuses HTTP CONNECT response smuggling, wrong status and oversized framing on a real stream', function (): void {
    foreach ([
        "HTTP/1.1 407 Proxy Authentication Required\r\n\r\n",
        "HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\n\r\n",
        "HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n",
        "HTTP/1.1 200 OK\r\nConnection: close\r\n\r\n",
        "HTTP/1.1 200 OK\r\n obs-fold: yes\r\n\r\n",
        "HTTP/1.1 200 OK\r\nMalformed\r\n\r\n",
        "HTTP/1.1 200 OK\r\nX-Fill: " . str_repeat('x', 8192) . "\r\n\r\n",
    ] as $reply) {
        runwireProxyAdversarialReply('http', $reply);
    }
});

it('refuses SOCKS5 authentication substitution and incorrect version on a real stream', function (): void {
    foreach (["\x05\x02", "\x05\xff", "\x04\x00", "\x05\x00\x00"] as $reply) {
        runwireProxyAdversarialReply('socks5', $reply);
    }
});

it('refuses malformed SOCKS5 CONNECT replies after a separately successful method exchange', function (): void {
    foreach ([
        "\x05\x05\x00\x01\x7f\x00\x00\x01\x00\x50",
        "\x05\x00\x01\x01\x7f\x00\x00\x01\x00\x50",
        "\x05\x00\x00\x06\x7f\x00\x00\x01\x00\x50",
        "\x05\x00\x00\x03\x00\x00\x50",
    ] as $reply) {
        runwireProxyAdversarialReply('socks5', $reply, afterMethod: true);
    }
});
