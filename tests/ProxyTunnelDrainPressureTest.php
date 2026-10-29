<?php

declare(strict_types=1);

use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Network\Connection;
use Infocyph\Runwire\Network\ConnectionFactory;
use Infocyph\Runwire\Network\ConnectionLimits;
use Infocyph\Runwire\Network\Proxy\HttpConnectHandshake;
use Infocyph\Runwire\Network\Proxy\HttpConnectTunnel;
use Infocyph\Runwire\Network\Proxy\Socks5Handshake;
use Infocyph\Runwire\Network\Proxy\Socks5Tunnel;

it('drains a pressure-limited CONNECT or SOCKS5 greeting before transferring tunnel callbacks', function (): void {
    foreach (['http', 'socks5'] as $protocol) {
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        expect($sockets)->toBeArray();
        [$socket, $peer] = $sockets;
        stream_set_blocking($peer, false);
        $loop = new SelectLoop();
        $limits = new ConnectionLimits(
            sendLowWatermarkBytes: 1,
            sendHighWatermarkBytes: 2,
            maxSendBufferBytes: 1024,
            maxWriteBytesPerTick: 1,
        );
        $connection = ConnectionFactory::accepted($loop, $socket, $limits);
        $captured = '';
        $stage = 0;
        $body = '';
        $callbacks = [];
        $peerWatcher = null;
        $watchdog = null;
        try {
            $tunnel = $protocol === 'http'
                ? new HttpConnectTunnel(
                    $loop, $connection, new HttpConnectHandshake(), 'origin.example', 443,
                    static function (?Connection $ready, ?Throwable $error) use (&$callbacks, &$body, $loop): void {
                        $callbacks[] = [$ready, $error];
                        if ($ready !== null) {
                            $ready->onData(static function (Connection $owner) use (&$body, $loop): void {
                                $body .= $owner->read();
                                $loop->stop();
                            });
                        } else {
                            $loop->stop();
                        }
                    },
                )
                : new Socks5Tunnel(
                    $loop, $connection, new Socks5Handshake(), '127.0.0.1', 443,
                    static function (?Connection $ready, ?Throwable $error) use (&$callbacks, &$body, $loop): void {
                        $callbacks[] = [$ready, $error];
                        if ($ready !== null) {
                            $ready->onData(static function (Connection $owner) use (&$body, $loop): void {
                                $body .= $owner->read();
                                $loop->stop();
                            });
                        } else {
                            $loop->stop();
                        }
                    },
                );
            $peerWatcher = $loop->onReadable($peer, static function () use (
                $peer, $loop, $protocol, &$captured, &$stage, &$peerWatcher,
            ): void {
                $read = fread($peer, 4096);
                if (!is_string($read) || $read === '') {
                    return;
                }
                $captured .= $read;
                if ($protocol === 'http') {
                    if (!str_contains($captured, "\r\n\r\n")) {
                        return;
                    }
                    fwrite($peer, "HTTP/1.1 200 Connection Established\r\n\r\n" . 'through-tunnel');
                } elseif ($stage === 0) {
                    if (strlen($captured) < 3) {
                        return;
                    }
                    expect($captured)->toBe("\x05\x01\x00");
                    $captured = '';
                    ++$stage;
                    fwrite($peer, "\x05\x00");

                    return;
                } else {
                    if (strlen($captured) < 10) {
                        return;
                    }
                    expect($captured)->toBe("\x05\x01\x00\x01\x7f\x00\x00\x01\x01\xbb");
                    fwrite($peer, "\x05\x00\x00\x01\x7f\x00\x00\x01\x01\xbb" . 'through-tunnel');
                }
                $loop->cancel($peerWatcher);
            });
            $watchdog = $loop->delay(1.5, static function () use ($loop): void {
                $loop->stop();
            });
            $tunnel->start();
            $loop->run();
            expect($callbacks)->toHaveCount(1)
                ->and($callbacks[0][0])->toBe($connection)
                ->and($callbacks[0][1])->toBeNull()
                ->and($body)->toBe('through-tunnel')
                ->and($connection->backpressureEvents())->toBeGreaterThan(0);
        } finally {
            if ($peerWatcher !== null) {
                $loop->cancel($peerWatcher);
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
});
