<?php

declare(strict_types=1);

use Infocyph\Runwire\Network\Internal\ByteBudget;
use Infocyph\Runwire\WebSocket\Internal\WebSocketFrameParser;
use Infocyph\Runwire\WebSocket\Internal\WebSocketProtocolException;
use Infocyph\Runwire\WebSocket\Internal\WebSocketWireCodec;
use Infocyph\Runwire\WebSocket\WebSocketOptions;

it('preserves binary data and releases the worker budget across all RFC6455 length thresholds', function (): void {
    $options = new WebSocketOptions(
        maxFramePayloadBytes: 65_536,
        maxMessageBytes: 131_072,
        maxBufferedBytes: 131_072,
    );
    $budget = new ByteBudget(131_072);

    foreach ([0, 1, 2, 3, 31, 125, 126, 127, 255, 1_024, 65_535, 65_536] as $size) {
        $pattern = hash('sha256', 'wire-' . $size, true);
        $payload = substr(str_repeat($pattern, intdiv($size + 31, 32)), 0, $size);
        $wire = WebSocketWireCodec::clientFrame(0x2, $payload);
        $parser = new WebSocketFrameParser($options, $budget);
        $frames = [];
        for ($offset = 0, $length = strlen($wire); $offset < $length; $offset += 97) {
            $parser->append(substr($wire, $offset, 97));
            array_push($frames, ...$parser->parse(1));
        }

        expect($frames)->toHaveCount(1)
            ->and($frames[0]->opcode)->toBe(0x2)
            ->and($frames[0]->payload)->toBe($payload)
            ->and($parser->bufferedBytes())->toBe(0)
            ->and($budget->used())->toBe(0);
    }
});

it('rejects reserved flags and opcodes on both WebSocket masking roles', function (): void {
    $options = new WebSocketOptions();
    foreach ([0xC1, 0xA1, 0x91, 0xF1, 0x83, 0x8B, 0x8F] as $first) {
        $parser = new WebSocketFrameParser($options);
        $parser->append(chr($first) . "\x80" . "\x01\x02\x03\x04");
        expect(static fn() => $parser->parse(1))
            ->toThrow(WebSocketProtocolException::class);
    }

    $unmaskedClient = new WebSocketFrameParser($options);
    $unmaskedClient->append(WebSocketWireCodec::frame(0x1, 'bad'));
    expect(static fn() => $unmaskedClient->parse(1))
        ->toThrow(WebSocketProtocolException::class, 'must be masked');

    $maskedServer = new WebSocketFrameParser($options, expectMasked: false);
    $maskedServer->append(WebSocketWireCodec::clientFrame(0x1, 'bad'));
    expect(static fn() => $maskedServer->parse(1))
        ->toThrow(WebSocketProtocolException::class, 'must not be masked');
});

it('fails closed on nonminimal lengths and the reserved 64-bit high bit', function (): void {
    $options = new WebSocketOptions();
    foreach ([
        "\x82\xFE\x00\x7D",
        "\x82\xFF\x00\x00\x00\x00\x00\x00\xFF\xFF",
        "\x82\xFF\x80\x00\x00\x00\x00\x01\x00\x00",
    ] as $prefix) {
        $parser = new WebSocketFrameParser($options);
        $parser->append($prefix);
        expect(static fn() => $parser->parse(1))
            ->toThrow(WebSocketProtocolException::class);
    }
});

it('rejects malformed close codes and invalid UTF-8 close reasons', function (): void {
    foreach ([1005, 1006, 1015, 2999] as $code) {
        expect(static fn() => WebSocketWireCodec::parseClosePayload(pack('n', $code)))
            ->toThrow(WebSocketProtocolException::class);
    }
    expect(static fn() => WebSocketWireCodec::parseClosePayload("\x03\xE8\xFF"))
        ->toThrow(WebSocketProtocolException::class, 'UTF-8');
});
