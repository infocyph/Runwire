<?php

declare(strict_types=1);

use Infocyph\Runwire\WebSocket\Internal\WebSocketClientHandshake;

it('builds secure independent origin-form upgrades with random nonce and bounded offers', function (): void {
    $one = WebSocketClientHandshake::build('wss://example.test:8443/chat?room=1', ['chat', 'superchat']);
    $two = WebSocketClientHandshake::build('wss://example.test/chat');

    expect($one['secure'])->toBeTrue()
        ->and($one['port'])->toBe(8443)
        ->and($one['wire'])->toContain(
            "GET /chat?room=1 HTTP/1.1\r\n",
            "Host: example.test:8443\r\n",
            "Sec-WebSocket-Protocol: chat, superchat\r\n",
        )
        ->and(strlen((string) base64_decode($one['key'], true)))->toBe(16)
        ->and($one['key'])->not->toBe($two['key']);
});

it('validates the RFC6455 example accept nonce and selected offered subprotocol', function (): void {
    $response = "HTTP/1.1 101 Switching Protocols\r\n"
        . "Upgrade: websocket\r\nConnection: keep-alive, Upgrade\r\n"
        . "Sec-WebSocket-Accept: s3pPLMBiTxaQ9kYGzzhZRbK+xOo=\r\n"
        . "Sec-WebSocket-Protocol: chat\r\n\r\n";
    $key = 'dGhlIHNhbXBsZSBub25jZQ==';

    expect(WebSocketClientHandshake::verify($response, $key, ['chat']))->toBe('chat');
    expect(WebSocketClientHandshake::verify(
        str_replace("Sec-WebSocket-Protocol: chat\r\n", '', $response),
        $key,
    ))->toBeNull();
});

it('rejects credentials, injected request target, wrong response, extensions and unoffered roles', function (): void {
    $key = 'dGhlIHNhbXBsZSBub25jZQ==';
    $valid = "HTTP/1.1 101 Switching Protocols\r\n"
        . "Upgrade: websocket\r\nConnection: Upgrade\r\n"
        . "Sec-WebSocket-Accept: s3pPLMBiTxaQ9kYGzzhZRbK+xOo=\r\n\r\n";

    expect(fn() => WebSocketClientHandshake::build('wss://user:pass@example.test/x'))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn() => WebSocketClientHandshake::build("ws://example.test/\r\nBad: 1"))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn() => WebSocketClientHandshake::build('ws://example.test/x#fragment'))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn() => WebSocketClientHandshake::build('ws://example.test', ['chat', 'chat']))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn() => WebSocketClientHandshake::verify(str_replace('s3pPLM', 'notPLM', $valid), $key))
        ->toThrow(RuntimeException::class)
        ->and(fn() => WebSocketClientHandshake::verify(str_replace('101', '200', $valid), $key))
        ->toThrow(RuntimeException::class)
        ->and(fn() => WebSocketClientHandshake::verify(
            str_replace("\r\n\r\n", "\r\nSec-WebSocket-Protocol: secret\r\n\r\n", $valid),
            $key,
            ['chat'],
        ))->toThrow(RuntimeException::class)
        ->and(fn() => WebSocketClientHandshake::verify(
            str_replace("\r\n\r\n", "\r\nSec-WebSocket-Extensions: permessage-deflate\r\n\r\n", $valid),
            $key,
        ))->toThrow(RuntimeException::class)
        ->and(fn() => WebSocketClientHandshake::verify(
            str_replace("\r\n\r\n", "\r\nUpgrade: websocket\r\n\r\n", $valid),
            $key,
        ))->toThrow(RuntimeException::class);
});

it('round-trips the upgrade challenge with an independent real Unix socket peer', function (): void {
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    expect($pair)->toBeArray()->toHaveCount(2);
    [$client, $peer] = $pair;

    try {
        $request = WebSocketClientHandshake::build('ws://example.test/chat', ['chat']);
        expect(fwrite($client, $request['wire']))->toBe(strlen($request['wire']));
        $wire = fread($peer, 4_096);
        expect($wire)->toContain("Upgrade: websocket\r\n", 'Sec-WebSocket-Key: ' . $request['key']);

        $accept = base64_encode(sha1($request['key'] . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
        $response = "HTTP/1.1 101 Switching Protocols\r\n"
            . "Upgrade: websocket\r\nConnection: Upgrade\r\n"
            . "Sec-WebSocket-Accept: $accept\r\nSec-WebSocket-Protocol: chat\r\n\r\n";
        expect(fwrite($peer, $response))->toBe(strlen($response));
        $reply = fread($client, 4_096);
        expect(WebSocketClientHandshake::verify($reply, $request['key'], ['chat']))->toBe('chat');
    } finally {
        fclose($client);
        fclose($peer);
    }
});

