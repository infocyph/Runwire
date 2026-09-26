<?php

declare(strict_types=1);

use Infocyph\Runwire\Network\Internal\ByteBudget;
use Infocyph\Runwire\WebSocket\Internal\WebSocketFrameParser;
use Infocyph\Runwire\WebSocket\Internal\WebSocketProtocolException;
use Infocyph\Runwire\WebSocket\WebSocketOptions;

function maskedWebSocketFrame(int $opcode, string $payload, bool $fin = true): string
{
    $mask = "\x11\x22\x33\x44";
    $length = strlen($payload);
    $first = ($fin ? 0x80 : 0x00) | $opcode;

    if ($length < 126) {
        $head = chr($first) . chr(0x80 | $length);
    } elseif ($length <= 65_535) {
        $head = chr($first) . chr(0x80 | 126) . pack('n', $length);
    } else {
        $head = chr($first) . chr(0x80 | 127) . pack('NN', 0, $length);
    }

    $masked = $payload;
    for ($index = 0; $index < $length; ++$index) {
        $masked[$index] = $masked[$index] ^ $mask[$index & 3];
    }

    return $head . $mask . $masked;
}

it('parses masked frames incrementally without exposing mask bytes', function (): void {
    $parser = new WebSocketFrameParser(new WebSocketOptions());
    $wire = maskedWebSocketFrame(0x1, 'hello');

    $parser->append(substr($wire, 0, 3));
    expect($parser->parse(8))->toBe([]);

    $parser->append(substr($wire, 3));
    $frames = $parser->parse(8);

    expect($frames)->toHaveCount(1)
        ->and($frames[0]->fin)->toBeTrue()
        ->and($frames[0]->opcode)->toBe(0x1)
        ->and($frames[0]->payload)->toBe('hello')
        ->and($parser->bufferedBytes())->toBe(0);
});

it('accepts minimal 16-bit and 64-bit frame lengths within the configured ceiling', function (): void {
    $parser = new WebSocketFrameParser(new WebSocketOptions(
        maxFramePayloadBytes: 65_536,
        maxMessageBytes: 131_072,
    ));

    $payload126 = str_repeat('a', 126);
    $payload65536 = str_repeat('b', 65_536);
    $parser->append(maskedWebSocketFrame(0x2, $payload126));
    $parser->append(maskedWebSocketFrame(0x2, $payload65536));

    $frames = $parser->parse(2);

    expect($frames)->toHaveCount(2)
        ->and($frames[0]->payload)->toBe($payload126)
        ->and(strlen($frames[1]->payload))->toBe(65_536)
        ->and($frames[1]->payload)->toBe($payload65536);
});

it('rejects unmasked, reserved and invalid control frames', function (): void {
    $options = new WebSocketOptions();

    $unmasked = new WebSocketFrameParser($options);
    $unmasked->append("\x81\x01x");
    expect(static fn() => $unmasked->parse(1))
        ->toThrow(WebSocketProtocolException::class, 'must be masked');

    $reserved = new WebSocketFrameParser($options);
    $reserved->append(maskedWebSocketFrame(0x3, 'x'));
    expect(static fn() => $reserved->parse(1))
        ->toThrow(WebSocketProtocolException::class, 'opcode');

    $fragmentedPing = new WebSocketFrameParser($options);
    $fragmentedPing->append(maskedWebSocketFrame(0x9, 'x', false));
    expect(static fn() => $fragmentedPing->parse(1))
        ->toThrow(WebSocketProtocolException::class, 'control frames');
});

it('rejects non-minimal lengths and configured frame ceilings', function (): void {
    $mask = "\x01\x02\x03\x04";

    $nonMinimal = new WebSocketFrameParser(new WebSocketOptions());
    $nonMinimal->append("\x81\xFE\x00\x7D" . $mask . str_repeat('x', 125));
    expect(static fn() => $nonMinimal->parse(1))
        ->toThrow(WebSocketProtocolException::class, 'non-minimal');

    $oversized = new WebSocketFrameParser(new WebSocketOptions(maxFramePayloadBytes: 128));
    $oversized->append(maskedWebSocketFrame(0x2, str_repeat('x', 129)));
    expect(static fn() => $oversized->parse(1))
        ->toThrow(WebSocketProtocolException::class, 'payload exceeds');
});

it('charges parser bytes to the shared worker budget', function (): void {
    $budget = new ByteBudget(32);
    $parser = new WebSocketFrameParser(
        new WebSocketOptions(maxFramePayloadBytes: 16, maxMessageBytes: 32, maxBufferedBytes: 32),
        $budget,
    );

    $parser->append(maskedWebSocketFrame(0x1, 'hello'));
    expect($budget->used())->toBeGreaterThan(0);

    $parser->parse(1);
    expect($budget->used())->toBe(0);

    $parser->append(str_repeat('x', 32));
    expect(static fn() => $parser->append('y'))
        ->toThrow(WebSocketProtocolException::class, 'byte budget');
});
