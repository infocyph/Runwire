<?php

declare(strict_types=1);

use Infocyph\Runwire\Http\Client\Http1ClientResponseParser;
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
use Infocyph\Runwire\Http\Http3\Qpack\DynamicTable as QpackTable;
use Infocyph\Runwire\Http\Http3\Qpack\FieldSectionDecoder;
use Infocyph\Runwire\Network\Internal\QuicWorkerCommand;

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

it('keeps well-formed H1 sibling responses alive across malformed peer framing corpus', function (): void {
    for ($index = 1; $index <= 32; ++$index) {
        $hostile = new Http1ClientResponseParser();
        $wire = "HTTP/1.1 200 OK\r\nContent-Length: " . $index
            . "\r\nTransfer-Encoding: chunked\r\n\r\n";
        expect(fn() => $hostile->push(
            $wire,
            static function (): void {},
            static function (): void {},
            static function (): void {},
        ))->toThrow(RuntimeException::class);

        $headers = 0;
        $body = '';
        $healthy = new Http1ClientResponseParser();
        $healthy->push(
            "HTTP/1.1 200 OK\r\nContent-Length: 1\r\n\r\n" . chr(65 + $index % 26),
            static function () use (&$headers): void { ++$headers; },
            static function (string $chunk) use (&$body): void { $body .= $chunk; },
            static function (): void {},
        );
        expect($healthy->done())->toBeTrue()
            ->and($headers)->toBe(1)
            ->and(strlen($body))->toBe(1);
    }
});

it('rejects 64 oversized QPACK field sections without affecting another peer decoder', function (): void {
    for ($index = 0; $index < 64; ++$index) {
        $malicious = new FieldSectionDecoder(new QpackTable(0), 64);
        expect(fn() => $malicious->decode(str_repeat('x', 65 + $index)))
            ->toThrow(Http3Exception::class);
        $healthy = new FieldSectionDecoder(new QpackTable(0), 64);
        expect($healthy->decode("\x00\x00")->fields)->toBe([]);
    }
});

it('keeps 64 bounded QUIC worker command decoders independent of invalid IPC frames', function (): void {
    for ($index = 0; $index < 64; ++$index) {
        $invalid = str_repeat('!', 90_001 + $index) . "\n";
        expect(fn() => QuicWorkerCommand::decode($invalid))->toThrow(InvalidArgumentException::class);
        $wire = QuicWorkerCommand::encode($index + 1, 'write', 4, "\x00binary", false);
        $decoded = QuicWorkerCommand::decode($wire);
        expect($decoded['id'])->toBe($index + 1)
            ->and($decoded['data'])->toBe("\x00binary")
            ->and($decoded['stream'])->toBe(4);
    }
});
