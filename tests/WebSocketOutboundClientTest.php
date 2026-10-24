<?php

declare(strict_types=1);

use Infocyph\Runwire\Executor\ProcessExecutor;
use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Network\AsyncDnsResolver;
use Infocyph\Runwire\Network\Dialer;
use Infocyph\Runwire\Network\DialOptions;
use Infocyph\Runwire\Network\HappyEyeballsDialer;
use Infocyph\Runwire\Network\TlsClientOptions;
use Infocyph\Runwire\WebSocket\Internal\WebSocketWireCodec;
use Infocyph\Runwire\WebSocket\WebSocketClient;
use Infocyph\Runwire\WebSocket\WebSocketSession;

it('upgrades a real outbound TCP socket against an independent peer and delivers coalesced data', function (): void {
    $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $reason);
    expect($server)->not->toBeFalse();
    stream_set_blocking($server, false);
    $address = stream_socket_get_name($server, false);
    expect($address)->toBeString();
    $port = (int) substr(strrchr($address, ':'), 1);

    $loop = new SelectLoop();
    $executor = new ProcessExecutor($loop, 1, 4);
    $dialer = new Dialer($loop);
    $race = new HappyEyeballsDialer($loop, $dialer, new AsyncDnsResolver($executor));
    $peer = null;
    $peerWatcher = null;
    $wire = '';
    $seen = [];
    $sessions = [];
    $callbacks = 0;
    $acceptWatcher = $loop->onReadable($server, function () use ($server, $loop, &$peer, &$peerWatcher, &$wire): void {
        $peer = stream_socket_accept($server, 0);
        if (!is_resource($peer)) {
            return;
        }
        stream_set_blocking($peer, false);
        $peerWatcher = $loop->onReadable($peer, function ($stream) use (&$wire): void {
            $chunk = fread($stream, 8_192);
            if (!is_string($chunk) || $chunk === '') {
                return;
            }
            $wire .= $chunk;
            if (!str_contains($wire, "\r\n\r\n")) {
                return;
            }
            preg_match('/^Sec-WebSocket-Key: (.+)$/mi', $wire, $parts);
            $key = trim($parts[1] ?? '');
            $accept = base64_encode(sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
            $reply = "HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\n"
                . "Sec-WebSocket-Accept: " . $accept . "\r\nSec-WebSocket-Protocol: chat\r\n\r\n"
                . WebSocketWireCodec::frame(0x1, 'from-peer');
            fwrite($stream, $reply);
            $wire = '';
        });
    });
    $watchdog = $loop->delay(1.0, static function () use ($loop): void {
        $loop->stop();
    });

    try {
        WebSocketClient::connect(
            $loop,
            $race,
            'ws://127.0.0.1:' . $port . '/chat',
            function (?WebSocketSession $session, ?Throwable $error) use (&$seen, &$sessions, &$callbacks, $loop): void {
                ++$callbacks;
                expect($error)->toBeNull();
                expect($session)->toBeInstanceOf(WebSocketSession::class);
                $sessions[] = $session;
                $session->onMessage(function (WebSocketSession $session, $message) use (&$seen, $loop): void {
                    expect($session)->toBeInstanceOf(WebSocketSession::class);
                    $seen = $message->data;
                    $loop->stop();
                });
            },
            ['chat'],
        );
        $loop->run();
        expect($callbacks)->toBe(1)
            ->and($seen)->toBe(['from-peer'])
            ->and($sessions[0]->subprotocol())->toBe('chat');
    } finally {
        foreach ($sessions as $session) {
            $session->drain();
        }
        $race->close();
        $dialer->close();
        $executor->close();
        $loop->cancel($watchdog);
        $loop->cancel($acceptWatcher);
        if (is_int($peerWatcher)) {
            $loop->cancel($peerWatcher);
        }
        if (is_resource($peer)) {
            fclose($peer);
        }
        fclose($server);
    }
});

it('rejects oversized upgrade response headers without unbounded buffering', function (): void {
    $loop = new SelectLoop();
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    expect($pair)->toBeArray()->toHaveCount(2);
    [$socket, $peer] = $pair;
    stream_set_blocking($peer, false);
    $connection = new \Infocyph\Runwire\Network\Connection($loop, $socket);
    $request = \Infocyph\Runwire\WebSocket\Internal\WebSocketClientHandshake::build('ws://localhost/');
    $errors = [];
    $upgrade = new \Infocyph\Runwire\WebSocket\Internal\WebSocketClientUpgrade(
        $loop, $connection, $request, [], new \Infocyph\Runwire\WebSocket\WebSocketOptions(),
        new DialOptions(), static function ($session, $error) use (&$errors): void {
            expect($session)->toBeNull();
            $errors = $error;
        },
    );
    $stop = $loop->delay(0.5, static function () use ($loop): void { $loop->stop(); });
    try {
        $upgrade->start();
        fwrite($peer, "HTTP/1.1 101 Switching Protocols\r\nX-Fill: " . str_repeat('a', 9_000));
        $loop->run();
        expect($errors)->toHaveCount(1);
        expect($errors[0])->toBeInstanceOf(RuntimeException::class);
    } finally {
        $loop->cancel($stop);
        $connection->abort();
        fclose($peer);
    }
});

it('refuses TLS downgrade and mismatched TLS or ALPN before opening a socket', function (): void {
    $loop = new SelectLoop();
    $executor = new ProcessExecutor($loop, 1, 2);
    $dialer = new Dialer($loop);
    $race = new HappyEyeballsDialer($loop, $dialer, new AsyncDnsResolver($executor));

    try {
        $complete = static function (): void {};
        expect(fn() => WebSocketClient::connect($loop, $race, 'ws://127.0.0.1/', $complete, dialOptions: new DialOptions(
            tls: new TlsClientOptions('127.0.0.1', alpnProtocols: ['http/1.1']),
        )))->toThrow(InvalidArgumentException::class)
            ->and(fn() => WebSocketClient::connect($loop, $race, 'wss://127.0.0.1/', $complete, dialOptions: new DialOptions(
                tls: new TlsClientOptions('another.test', alpnProtocols: ['http/1.1']),
            )))->toThrow(InvalidArgumentException::class)
            ->and(fn() => WebSocketClient::connect($loop, $race, 'wss://127.0.0.1/', $complete, dialOptions: new DialOptions(
                tls: new TlsClientOptions('127.0.0.1', alpnProtocols: ['h2', 'http/1.1']),
            )))->toThrow(InvalidArgumentException::class)
            ->and($dialer->pendingCount())->toBe(0);
    } finally {
        $race->close();
        $dialer->close();
        $executor->close();
    }
});
