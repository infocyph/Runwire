<?php

declare(strict_types=1);

use Infocyph\Runwire\Http\Http2\Enum\FrameType as H2FrameType;
use Infocyph\Runwire\Http\Http2\Frame as H2Frame;
use Infocyph\Runwire\Http\Http2\FrameParser as H2Parser;
use Infocyph\Runwire\Http\Http2\FrameWriter as H2Writer;
use Infocyph\Runwire\Http\Http2\Hpack\Decoder as HpackDecoder;
use Infocyph\Runwire\Http\Http2\Hpack\HpackException;
use Infocyph\Runwire\Http\Http2\Internal\ConnectionError;
use Infocyph\Runwire\Http\Http3\Enum\FrameType as H3FrameType;
use Infocyph\Runwire\Http\Http3\Frame as H3Frame;
use Infocyph\Runwire\Http\Http3\FrameParser as H3Parser;
use Infocyph\Runwire\Http\Http3\Http3Exception;
use Infocyph\Runwire\Http\Http3\VarIntCodec;

/**
 * Reproducible bounds corpus; each hostile decoder has a fully independent
 * healthy peer to demonstrate that malformed framing never poisons siblings.
 */
it('rejects 128 oversized H2 frame declarations while separate peers continue streaming', function (): void {
    for ($index = 0; $index < 128; ++$index) {
        $declared = 16_385 + $index;
        $header = chr(($declared >> 16) & 255)
            . chr(($declared >> 8) & 255) . chr($declared & 255)
            . chr(H2FrameType::DATA->value) . "\x00" . pack('N', 1);
        $hostile = new H2Parser();
        expect(fn() => $hostile->push($header))->toThrow(ConnectionError::class);

        $healthy = new H2Parser();
        $wire = H2Writer::encode(new H2Frame(H2FrameType::DATA->value, 1, 3, 'survivor'));
        $frames = [];
        foreach (str_split($wire, 3) as $chunk) {
            array_push($frames, ...$healthy->push($chunk));
        }
        expect($frames)->toHaveCount(1)
            ->and($frames[0]->payload)->toBe('survivor')
            ->and($frames[0]->streamId)->toBe(3)
            ->and($healthy->bufferedBytes())->toBe(0);
    }
});

it('rejects 128 oversized H3 frame declarations before bodies and preserves sibling decoding', function (): void {
    for ($index = 0; $index < 128; ++$index) {
        $hostile = new H3Parser(32);
        $wire = VarIntCodec::encode(H3FrameType::DATA->value) . VarIntCodec::encode(33 + $index);
        expect(fn() => $hostile->push($wire))->toThrow(Http3Exception::class)
            ->and($hostile->bufferedBytes())->toBeLessThanOrEqual(16);

        $healthy = new H3Parser(32);
        $frames = [];
        foreach (str_split((new H3Frame(H3FrameType::DATA->value, 'survivor'))->encode(), 2) as $chunk) {
            array_push($frames, ...$healthy->push($chunk));
        }
        expect($frames)->toHaveCount(1)
            ->and($frames[0]->payload)->toBe('survivor')
            ->and($healthy->bufferedBytes())->toBe(0);
    }
});

it('isolates malformed HPACK representations from a healthy decoder and rejects dynamic updates after headers', function (): void {
    for ($index = 0; $index < 32; ++$index) {
        $hostile = new HpackDecoder(maxDynamicTableBytes: 0, maxHeaderListBytes: 64);
        expect(fn() => $hostile->decode("\x80"))->toThrow(HpackException::class);
        expect(fn() => $hostile->decode("\x82\x20"))->toThrow(HpackException::class);

        $sibling = new HpackDecoder(maxDynamicTableBytes: 0, maxHeaderListBytes: 64);
        expect($sibling->decode("\x82"))->toBe([[':method', 'GET']])
            ->and($sibling->dynamicTableBytes())->toBe(0);
    }
});
