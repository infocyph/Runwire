<?php

declare(strict_types=1);

use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Network\Connection;
use Infocyph\Runwire\Network\Dialer;
use Infocyph\Runwire\Network\Proxy\HttpConnectHandshake;
use Infocyph\Runwire\Network\Proxy\HttpConnectTunnel;
use Infocyph\Runwire\Network\Proxy\Socks5Handshake;
use Infocyph\Runwire\Network\Proxy\Socks5Tunnel;

/**
 * A loop-backed real TCP proxy peer; do not confuse with independent external
 * CONNECT/SOCKS5 reference conformance or nested original-target TLS tests.
 *
 * @return array{request:string,reply:string,callbacks:int,payload:string}
 */
function runwireProxyLoopbackInterop(string $protocol): array
{
    $listener = stream_socket_server('tcp://127.0.0.1:0', $code, $error, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN);
    expect($listener)->toBeResource();
    stream_set_blocking($listener, false);
    $address = stream_socket_get_name($listener, false);
    expect($address)->toBeString();
    $port = (int) substr(strrchr($address, ':'), 1);
    $loop = new SelectLoop();
    $dialer = new Dialer($loop);
    $serverWatcher = null;
    $peerWatcher = null;
    $peer = null;
    $client = null;
    $header = '';
    $reply = '';
    $phase = 0;
    $callbacks = 0;
    $received = '';
    $failure = null;
    $watchdog = $loop->delay(3, static function () use (&$failure, $loop): void {
        $failure ??= new RuntimeException('Real TCP proxy integration timed out.');
        $loop->stop();
    });
    try {
        $serverWatcher = $loop->onReadable($listener, static function () use (
            $listener, $loop, &$peer, &$serverWatcher, &$peerWatcher,
            &$header, &$reply, &$phase,
            $protocol,
        ): void {
            $peer = stream_socket_accept($listener, 0);
            if (!is_resource($peer)) {
                throw new RuntimeException('Proxy test listener refused loopback client.');
            }
            stream_set_blocking($peer, false);
            $loop->cancel($serverWatcher);
            $peerWatcher = $loop->onReadable($peer, static function ($socket) use (
                &$header, &$reply, &$phase, $protocol, $loop, &$peerWatcher,
            ): void {
                $part = fread($socket, 4096);
                if (!is_string($part) || $part === '') {
                    return;
                }
                $header .= $part;
                if ($protocol === 'http') {
                    if (!str_contains($header, "\r\n\r\n")) {
                        return;
                    }
                    $reply = "HTTP/1.1 200 Connection Established\r\n\r\n";
                    fwrite($socket, $reply . 'payload-through-proxy');
                } elseif ($phase === 0) {
                    if (strlen($header) < 3) {
                        return;
                    }
                    if ($header !== "\x05\x01\x00") {
                        throw new RuntimeException('SOCKS5 proxy saw an unexpected greeting.');
                    }
                    $header = '';
                    ++$phase;
                    fwrite($socket, "\x05\x00");
                    return;
                } else {
                    if (strlen($header) < 10) {
                        return;
                    }
                    $reply = "\x05\x00\x00\x01\x7f\x00\x00\x01\x01\xbb";
                    fwrite($socket, $reply . 'payload-through-proxy');
                }
                $loop->cancel($peerWatcher);
            });
        });

        $dialer->connect('tcp://127.0.0.1:' . $port, static function (?Connection $conn, ?Throwable $error) use (
            $loop, $protocol, &$client, &$failure, &$callbacks, &$received,
        ): void {
            if ($error !== null || $conn === null) {
                $failure = $error ?? new RuntimeException('Real outbound proxy TCP dial failed.');
                $loop->stop();

                return;
            }
            $client = $conn;
            $done = static function (?Connection $ready, ?Throwable $error) use (
                &$callbacks, &$failure, &$received, $loop,
            ): void {
                ++$callbacks;
                if ($error !== null || $ready === null) {
                    $failure = $error ?? new RuntimeException('Proxy tunnel failed.');
                    $loop->stop();

                    return;
                }
                $ready->onData(static function (Connection $connection) use (&$received, $loop): void {
                    $received .= $connection->read();
                    $loop->stop();
                });
            };
            if ($protocol === 'http') {
                (new HttpConnectTunnel($loop, $conn, new HttpConnectHandshake(), 'origin.example', 443, $done))->start();
            } else {
                (new Socks5Tunnel($loop, $conn, new Socks5Handshake(), '127.0.0.1', 443, $done))->start();
            }
        });
        $loop->run();
        if ($failure !== null) {
            throw $failure;
        }
        expect($callbacks)->toBe(1)
            ->and($received)->toBe('payload-through-proxy');

        return ['request' => $header, 'reply' => $reply, 'callbacks' => $callbacks, 'payload' => $received];
    } finally {
        $loop->cancel($watchdog);
        if ($serverWatcher !== null) {
            $loop->cancel($serverWatcher);
        }
        if ($peerWatcher !== null) {
            $loop->cancel($peerWatcher);
        }
        $client?->abort();
        $dialer->close();
        if (is_resource($peer)) {
            fclose($peer);
        }
        fclose($listener);
        expect($loop->diagnostics()->readWatchers)->toBe(0)
            ->and($loop->diagnostics()->writeWatchers)->toBe(0);
    }
}

it('establishes a live outbound TCP HTTP CONNECT request through an independent proxy peer socket', function (): void {
    $result = runwireProxyLoopbackInterop('http');
    expect($result['request'])->toBe("CONNECT origin.example:443 HTTP/1.1\r\nHost: origin.example:443\r\n\r\n")
        ->and($result['callbacks'])->toBe(1);
});

it('establishes a live outbound TCP SOCKS5 handshake over the dialer and preserves tunnel bytes', function (): void {
    $result = runwireProxyLoopbackInterop('socks5');
    expect($result['request'])->toBe("\x05\x01\x00\x01\x7f\x00\x00\x01\x01\xbb")
        ->and($result['callbacks'])->toBe(1);
});
