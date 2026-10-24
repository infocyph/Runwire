<?php

declare(strict_types=1);

use Infocyph\Runwire\WebSocket\Internal\WebSocketFrameParser;
use Infocyph\Runwire\WebSocket\Internal\WebSocketProtocolException;
use Infocyph\Runwire\WebSocket\Internal\WebSocketWireCodec;
use Infocyph\Runwire\WebSocket\WebSocketOptions;

it('encodes fresh masked client frames and decodes them with existing bounded server parser', function (): void {
    $payloads = [
        '',
        'hello',
        "bin\0ary\xff",
        str_repeat('x', 125),
        str_repeat('z', 126),
        str_repeat('a', 65_536),
    ];

    foreach ($payloads as $payload) {
        $opcode = str_contains($payload, "\0") ? 0x2 : 0x1;
        $wire = WebSocketWireCodec::clientFrame($opcode, $payload);
        $parser = new WebSocketFrameParser(new WebSocketOptions(maxFramePayloadBytes: 1_048_576));
        $parser->append($wire);
        $frames = $parser->parse(1);
        expect($frames)->toHaveCount(1)
            ->and($frames[0]->opcode)->toBe($opcode)
            ->and($frames[0]->payload)->toBe($payload)
            ->and($frames[0]->fin)->toBeTrue()
            ->and($parser->bufferedBytes())->toBe(0);
    }
});

it('decodes unmasked server frames only in client peer mode', function (): void {
    $wire = WebSocketWireCodec::frame(0x1, 'hello-server');
    $client = new WebSocketFrameParser(new WebSocketOptions(), expectMasked: false);
    $client->append($wire);
    $frames = $client->parse(1);

    expect($frames)->toHaveCount(1)
        ->and($frames[0]->payload)->toBe('hello-server');

    $server = new WebSocketFrameParser(new WebSocketOptions());
    $server->append($wire);
    expect(fn() => $server->parse(1))->toThrow(WebSocketProtocolException::class);

    $client->append(WebSocketWireCodec::clientFrame(0x9, 'ping'));
    expect(fn() => $client->parse(1))->toThrow(WebSocketProtocolException::class);
});

it('keeps masked ping close and binary control limits compatible with the server', function (): void {
    $parser = new WebSocketFrameParser(new WebSocketOptions());
    $close = pack('n', 1000) . 'finished';
    $parser->append(WebSocketWireCodec::clientFrame(0x9, 'ping'));
    $parser->append(WebSocketWireCodec::clientFrame(0x8, $close));
    $frames = $parser->parse(2);

    expect($frames)->toHaveCount(2)
        ->and($frames[0]->opcode)->toBe(0x9)
        ->and($frames[1]->opcode)->toBe(0x8)
        ->and(WebSocketWireCodec::parseClosePayload($frames[1]->payload))->toBe([1000, 'finished'])
        ->and(fn() => WebSocketWireCodec::clientFrame(0xB, 'reserved'))->toThrow(InvalidArgumentException::class)
        ->and(fn() => WebSocketWireCodec::clientFrame(0x9, str_repeat('a', 126)))->toThrow(InvalidArgumentException::class)
        ->and(fn() => WebSocketWireCodec::clientFrame(0x9, 'ping', false))->toThrow(InvalidArgumentException::class);
});
