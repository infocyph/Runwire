<?php

declare(strict_types=1);

use Infocyph\Runwire\Http\Http1\Http1Connection;
use Infocyph\Runwire\Http\Http1\Http1Limits;
use Infocyph\Runwire\Http\HttpRequest;
use Infocyph\Runwire\Http\ResponseWriterInterface;
use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Network\Connection;
use Infocyph\Runwire\Network\ConnectionLimits;
use Infocyph\Runwire\WebSocket\WebSocketOptions;
use Infocyph\Runwire\WebSocket\WebSocketSession;
use Infocyph\Runwire\WebSocket\WebSocketUpgrade;

function runwireWsClientFrame(int $opcode, string $payload, bool $fin = true, bool $masked = true): string
{
    $mask = "\x12\x34\x56\x78";
    $length = strlen($payload);
    $first = ($fin ? 0x80 : 0x00) | $opcode;
    $maskBit = $masked ? 0x80 : 0x00;

    if ($length < 126) {
        $head = chr($first) . chr($maskBit | $length);
    } elseif ($length <= 65_535) {
        $head = chr($first) . chr($maskBit | 126) . pack('n', $length);
    } else {
        $head = chr($first) . chr($maskBit | 127) . pack('NN', 0, $length);
    }

    if (!$masked) {
        return $head . $payload;
    }

    $wire = $payload;
    for ($index = 0; $index < $length; ++$index) {
        $wire[$index] = $wire[$index] ^ $mask[$index & 3];
    }

    return $head . $mask . $wire;
}

function runwireWsHandshake(string $headers = '', string $frames = ''): string
{
    return "GET /ws HTTP/1.1\r\n"
        . "Host: localhost\r\n"
        . "Upgrade: websocket\r\n"
        . "Connection: keep-alive, Upgrade\r\n"
        . "Sec-WebSocket-Version: 13\r\n"
        . "Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==\r\n"
        . $headers
        . "\r\n"
        . $frames;
}

/** @return array{head: string, frames: list<array{opcode: int, payload: string}>} */
function runwireWsParseServerWire(string $wire): array
{
    $separator = strpos($wire, "\r\n\r\n");
    if ($separator === false) {
        return ['head' => $wire, 'frames' => []];
    }

    $head = substr($wire, 0, $separator + 4);
    $buffer = substr($wire, $separator + 4);
    $frames = [];

    while (strlen($buffer) >= 2) {
        $first = ord($buffer[0]);
        $second = ord($buffer[1]);
        if (($second & 0x80) !== 0) {
            throw new RuntimeException('Server WebSocket frames must not be masked.');
        }

        $opcode = $first & 0x0f;
        $indicator = $second & 0x7f;
        $offset = 2;
        if ($indicator < 126) {
            $length = $indicator;
        } elseif ($indicator === 126) {
            if (strlen($buffer) < 4) {
                break;
            }
            $decoded = unpack('nvalue', substr($buffer, 2, 2));
            $length = (int) ($decoded['value'] ?? 0);
            $offset = 4;
        } else {
            if (strlen($buffer) < 10) {
                break;
            }
            $high = unpack('Nvalue', substr($buffer, 2, 4));
            $low = unpack('Nvalue', substr($buffer, 6, 4));
            if ((int) ($high['value'] ?? 0) !== 0) {
                throw new RuntimeException('Test parser does not accept oversized server frames.');
            }
            $length = (int) ($low['value'] ?? 0);
            $offset = 10;
        }

        if (strlen($buffer) < $offset + $length) {
            break;
        }

        $frames[] = [
            'opcode' => $opcode,
            'payload' => substr($buffer, $offset, $length),
        ];
        $buffer = substr($buffer, $offset + $length);
    }

    return ['head' => $head, 'frames' => $frames];
}

function runwireWsExchange(
    string $clientWire,
    callable $handler,
    ?ConnectionLimits $connectionLimits = null,
    float $timeout = 0.25,
): string {
    [$server, $client] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    expect($server)->toBeResource()->and($client)->toBeResource();

    $loop = new SelectLoop();
    $connection = new Connection($loop, $server, $connectionLimits ?? new ConnectionLimits());
    new Http1Connection($loop, $connection, new Http1Limits(), $handler);
    $connection->onClose(static function () use ($loop): void {
        $loop->stop();
    });

    fwrite($client, $clientWire);
    $loop->delay($timeout, static function () use ($loop): void {
        $loop->stop();
    });
    $loop->run();

    stream_set_blocking($client, false);
    $wire = stream_get_contents($client);
    if ($connection->closeReason() === null) {
        $connection->abort();
    }
    fclose($client);

    return is_string($wire) ? $wire : '';
}

it('upgrades HTTP1, negotiates a subprotocol, and echoes a pipelined text frame', function (): void {
    $messages = [];
    $wire = runwireWsExchange(
        runwireWsHandshake(
            "Origin: https://example.test\r\nSec-WebSocket-Protocol: chat, superchat\r\n",
            runwireWsClientFrame(0x1, 'hello'),
        ),
        static function (HttpRequest $request, ResponseWriterInterface $writer) use (&$messages): void {
            $session = WebSocketUpgrade::accept(
                $request,
                $writer,
                originPolicy: static fn(string $origin): bool => $origin === 'https://example.test',
                subprotocol: 'chat',
                options: new WebSocketOptions(
                    heartbeatIntervalSeconds: 1.0,
                    idleTimeoutSeconds: 2.0,
                    closeTimeoutSeconds: 0.02,
                ),
            );
            expect($session)->toBeInstanceOf(WebSocketSession::class);
            $session?->onMessage(static function (WebSocketSession $session, $message) use (&$messages): void {
                $messages[] = $message->data;
                $session->sendText('echo:' . $message->data);
                $session->close(1000, 'done');
            });
        },
    );

    $parsed = runwireWsParseServerWire($wire);
    expect($parsed['head'])->toContain('HTTP/1.1 101 Switching Protocols')
        ->and($parsed['head'])->toContain('sec-websocket-accept: s3pPLMBiTxaQ9kYGzzhZRbK+xOo=')
        ->and($parsed['head'])->toContain('sec-websocket-protocol: chat')
        ->and($messages)->toBe(['hello'])
        ->and($parsed['frames'][0]['opcode'])->toBe(0x1)
        ->and($parsed['frames'][0]['payload'])->toBe('echo:hello')
        ->and($parsed['frames'][1]['opcode'])->toBe(0x8);
});

it('requires an explicit origin policy for browser-originated upgrades', function (): void {
    $wire = runwireWsExchange(
        runwireWsHandshake("Origin: https://example.test\r\n"),
        static function (HttpRequest $request, ResponseWriterInterface $writer): void {
            expect(WebSocketUpgrade::accept($request, $writer))->toBeNull();
        },
    );

    expect($wire)->toStartWith('HTTP/1.1 403 Forbidden');
});

it('closes with protocol error for unmasked client frames', function (): void {
    $wire = runwireWsExchange(
        runwireWsHandshake('', runwireWsClientFrame(0x1, 'bad', masked: false)),
        static function (HttpRequest $request, ResponseWriterInterface $writer): void {
            $session = WebSocketUpgrade::accept(
                $request,
                $writer,
                options: new WebSocketOptions(
                    heartbeatIntervalSeconds: 1.0,
                    idleTimeoutSeconds: 2.0,
                    closeTimeoutSeconds: 0.02,
                ),
            );
            $session?->onMessage(static function (): void {});
        },
    );

    $frames = runwireWsParseServerWire($wire)['frames'];
    $close = array_find($frames, static fn(array $frame): bool => $frame['opcode'] === 0x8);
    expect($close)->not->toBeNull();
    $code = unpack('nvalue', substr($close['payload'], 0, 2));
    expect((int) ($code['value'] ?? 0))->toBe(1002);
});

it('reassembles fragmented messages while answering interleaved ping frames', function (): void {
    $messages = [];
    $frames = runwireWsClientFrame(0x1, 'hel', false)
        . runwireWsClientFrame(0x9, 'p')
        . runwireWsClientFrame(0x0, 'lo');

    $wire = runwireWsExchange(
        runwireWsHandshake('', $frames),
        static function (HttpRequest $request, ResponseWriterInterface $writer) use (&$messages): void {
            $session = WebSocketUpgrade::accept(
                $request,
                $writer,
                options: new WebSocketOptions(
                    heartbeatIntervalSeconds: 1.0,
                    idleTimeoutSeconds: 2.0,
                    closeTimeoutSeconds: 0.02,
                ),
            );
            $session?->onMessage(static function (WebSocketSession $session, $message) use (&$messages): void {
                $messages[] = $message->data;
                $session->sendText($message->data);
                $session->close();
            });
        },
    );

    $serverFrames = runwireWsParseServerWire($wire)['frames'];
    expect($messages)->toBe(['hello'])
        ->and($serverFrames[0]['opcode'])->toBe(0xA)
        ->and($serverFrames[0]['payload'])->toBe('p')
        ->and($serverFrames[1]['opcode'])->toBe(0x1)
        ->and($serverFrames[1]['payload'])->toBe('hello');
});

it('enforces fragmented-message ceilings and idle close deadlines', function (): void {
    $oversized = runwireWsClientFrame(0x2, str_repeat('a', 15), false)
        . runwireWsClientFrame(0x0, str_repeat('b', 10));

    $wire = runwireWsExchange(
        runwireWsHandshake('', $oversized),
        static function (HttpRequest $request, ResponseWriterInterface $writer): void {
            $session = WebSocketUpgrade::accept(
                $request,
                $writer,
                options: new WebSocketOptions(
                    maxFramePayloadBytes: 16,
                    maxMessageBytes: 20,
                    maxBufferedBytes: 64,
                    heartbeatIntervalSeconds: 1.0,
                    idleTimeoutSeconds: 2.0,
                    closeTimeoutSeconds: 0.02,
                ),
            );
            $session?->onMessage(static function (): void {});
        },
    );

    $close = array_find(
        runwireWsParseServerWire($wire)['frames'],
        static fn(array $frame): bool => $frame['opcode'] === 0x8,
    );
    expect($close)->not->toBeNull();
    $code = unpack('nvalue', substr($close['payload'], 0, 2));
    expect((int) ($code['value'] ?? 0))->toBe(1009);

    $idleWire = runwireWsExchange(
        runwireWsHandshake(),
        static function (HttpRequest $request, ResponseWriterInterface $writer): void {
            WebSocketUpgrade::accept(
                $request,
                $writer,
                options: new WebSocketOptions(
                    heartbeatIntervalSeconds: 0.01,
                    idleTimeoutSeconds: 0.03,
                    closeTimeoutSeconds: 0.02,
                ),
            );
        },
        timeout: 0.12,
    );
    $idleClose = array_find(
        runwireWsParseServerWire($idleWire)['frames'],
        static fn(array $frame): bool => $frame['opcode'] === 0x8,
    );
    expect($idleClose)->not->toBeNull();
    $idleCode = unpack('nvalue', substr($idleClose['payload'], 0, 2));
    expect((int) ($idleCode['value'] ?? 0))->toBe(1001);
});
