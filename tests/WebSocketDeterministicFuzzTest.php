<?php

declare(strict_types=1);

use Infocyph\Runwire\Network\Internal\ByteBudget;
use Infocyph\Runwire\WebSocket\Internal\WebSocketFrameParser;
use Infocyph\Runwire\WebSocket\Internal\WebSocketProtocolException;
use Infocyph\Runwire\WebSocket\Internal\WebSocketWireCodec;
use Infocyph\Runwire\WebSocket\WebSocketOptions;

it('decodes 512 deterministic fragmented client and server frames without cross-peer leaks', function (): void {
    $options = new WebSocketOptions(
        maxFramePayloadBytes: 1_024,
        maxMessageBytes: 2_048,
        maxBufferedBytes: 4_096,
    );
    $memoryBefore = memory_get_usage(true);

    for ($index = 0; $index < 512; ++$index) {
        $size = $index % 997;
        $payload = substr(str_repeat(hash('sha256', 'peer-' . $index, true), 32), 0, $size);
        $masked = $index % 2 === 0;
        $wire = $masked
            ? WebSocketWireCodec::clientFrame(0x2, $payload)
            : WebSocketWireCodec::frame(0x2, $payload);
        $budget = new ByteBudget(4_096);
        $parser = new WebSocketFrameParser($options, $budget, $masked);
        $frames = [];
        $step = 1 + ($index % 37);
        for ($offset = 0; $offset < strlen($wire); $offset += $step) {
            $parser->append(substr($wire, $offset, $step));
            array_push($frames, ...$parser->parse(1));
        }
        expect($frames)->toHaveCount(1)
            ->and($frames[0]->payload)->toBe($payload)
            ->and($frames[0]->opcode)->toBe(0x2)
            ->and($parser->bufferedBytes())->toBe(0)
            ->and($budget->used())->toBe(0);
    }

    expect(memory_get_usage(true) - $memoryBefore)->toBeLessThan(8_388_608);
});

it('rejects 256 deterministic mask, opcode, control, RSV and oversized frame faults with no worker budget leak', function (): void {
    $options = new WebSocketOptions(
        maxFramePayloadBytes: 128,
        maxMessageBytes: 256,
        maxBufferedBytes: 256,
    );
    for ($index = 0; $index < 256; ++$index) {
        $kind = $index % 5;
        $wire = match ($kind) {
            0 => WebSocketWireCodec::frame(0x1, 'unmasked'),
            1 => WebSocketWireCodec::clientFrame(0x3, 'reserved'),
            2 => "\xC2\x80\x00\x00\x00\x00",
            3 => "\x09\x80\x00\x00\x00\x00",
            default => "\x82\xFE\x00\x81\x01\x02\x03\x04",
        };
        $budget = new ByteBudget(256);
        $parser = new WebSocketFrameParser($options, $budget);
        $parser->append($wire);
        expect(fn() => $parser->parse(1))->toThrow(WebSocketProtocolException::class);
        unset($parser);
        expect($budget->used())->toBe(0);
    }
});
