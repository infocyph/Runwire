<?php

declare(strict_types=1);

require_once __DIR__ . '/Support/SocketFixture.php';

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
 * @throws \InvalidArgumentException
 * @throws \OverflowException
 * @throws \RuntimeException
 * @throws \LogicException
 * @throws \Throwable
 */
function runwireProxyLoopbackInterop(string $protocol): array
{
    $listener = stream_socket_server('tcp://127.0.0.1:0', $code, $error, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN);
    \PHPUnit\Framework\Assert::assertIsResource($listener);
    stream_set_blocking($listener, false);
    $address = stream_socket_get_name($listener, false);
    \PHPUnit\Framework\Assert::assertIsString($address);
    if (($separator = strrpos($address, ':')) === false) {
        throw new RuntimeException('Independent proxy listener has no valid TCP port.');
    }
    $port = (int) substr($address, $separator + 1);
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
        $serverWatcher = $loop->onReadable($listener, static function (mixed $readyListener, int $callbackId) use (
            $loop,
            &$peer,
            &$peerWatcher,
            &$header,
            &$reply,
            &$phase,
            $protocol,
        ): void {
            $readyListener = \Infocyph\Runwire\Tests\Support\socketHandle($readyListener);
            $peer = stream_socket_accept($readyListener, 0);
            if (!is_resource($peer)) {
                throw new RuntimeException('Proxy test listener refused loopback client.');
            }
            stream_set_blocking($peer, false);
            $loop->cancel($callbackId);
            $peerWatcher = $loop->onReadable($peer, runwireLoopbackPeerReader($header, $reply, $phase, $protocol, $loop));
        });

        $dialer->connect('tcp://127.0.0.1:' . $port, static function (?Connection $conn, ?Throwable $error) use (
            $loop,
            $protocol,
            &$client,
            &$failure,
            &$callbacks,
            &$received,
        ): void {
            if ($error !== null || $conn === null) {
                $failure = $error ?? new RuntimeException('Real outbound proxy TCP dial failed.');
                $loop->stop();

                return;
            }
            $client = $conn;
            $done = runwireLoopbackProxyCompletion($callbacks, $failure, $received, $loop);
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
        runwireLoopbackProxyClose($loop, $serverWatcher, $peerWatcher, $client, $dialer, $peer);
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

/**
 * @return \Closure(?Connection,?Throwable):void
 */
function runwireLoopbackProxyCompletion(int &$callbacks, ?Throwable &$failure, string &$received, SelectLoop $loop): \Closure
{
    return static function (?Connection $ready, ?Throwable $error) use (
        &$callbacks,
        &$failure,
        &$received,
        $loop,
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
}

/**
 * @return \Closure(mixed,int):void
 */
function runwireLoopbackPeerReader(string &$header, string &$reply, int &$phase, string $protocol, SelectLoop $loop): \Closure
{
    return static function ($socket, int $callbackId) use (
        &$header,
        &$reply,
        &$phase,
        $protocol,
        $loop,
    ): void {
        \PHPUnit\Framework\Assert::assertIsResource($socket);
        $part = fread($socket, 4096);
        if (!is_string($part) || $part === '') {
            return;
        }
        $header .= $part;
        if (!runwireLoopbackPeerReply($socket, $header, $reply, $phase, $protocol)) {
            return;
        }
        $loop->cancel($callbackId);
    };
}

/**
 * @param resource $socket
 * @throws RuntimeException
 */
function runwireLoopbackPeerReply(mixed $socket, string &$header, string &$reply, int &$phase, string $protocol): bool
{
    if ($protocol === 'http') {
        if (!str_contains($header, "\r\n\r\n")) {
            return false;
        }
        $reply = "HTTP/1.1 200 Connection Established\r\n\r\n";
        fwrite($socket, $reply . 'payload-through-proxy');
    } elseif ($phase === 0) {
        if (strlen($header) < 3) {
            return false;
        }
        if ($header !== "\x05\x01\x00") {
            throw new RuntimeException('SOCKS5 proxy saw an unexpected greeting.');
        }
        $header = '';
        ++$phase;
        fwrite($socket, "\x05\x00");

        return false;
    } else {
        if (strlen($header) < 10) {
            return false;
        }
        $reply = "\x05\x00\x00\x01\x7f\x00\x00\x01\x01\xbb";
        fwrite($socket, $reply . 'payload-through-proxy');
    }

    return true;
}

/**
 * @param resource|closed-resource|false|null $peer
 * @throws \Throwable
 */
function runwireLoopbackProxyClose(SelectLoop $loop, ?int $serverWatcher, ?int $peerWatcher, ?Connection $client, Dialer $dialer, mixed $peer): void
{
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
}
