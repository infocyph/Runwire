<?php

declare(strict_types=1);

use Infocyph\Runwire\Protocol\CodecException;
use Infocyph\Runwire\Protocol\LengthPrefixedCodec;
use Infocyph\Runwire\Protocol\LengthPrefixFormat;
use Infocyph\Runwire\Protocol\LineCodec;
use Infocyph\Runwire\Protocol\RawCodec;

it('decodes fragmented line frames with a multi-byte delimiter', function (): void {
    $codec = new LineCodec("\r\n", 8);

    expect($codec->push('ab'))->toBe([])
        ->and($codec->push("c\r"))->toBe([])
        ->and($codec->push("\ndef\r\n"))->toBe(['abc', 'def'])
        ->and($codec->bufferedBytes())->toBe(0)
        ->and($codec->encode('x'))->toBe("x\r\n");
});

it('rejects oversized line payloads without mistaking arbitrary suffixes for delimiter prefixes', function (): void {
    $codec = new LineCodec("\r\n", 8);

    expect(fn () => $codec->push('123456789'))->toThrow(CodecException::class);
});

it('bounds line decoding by the requested frame batch', function (): void {
    $codec = new LineCodec("\n", 8);

    expect($codec->push("a\nb\nc\n", 2))->toBe(['a', 'b'])
        ->and($codec->push('', 2))->toBe(['c']);
});

it('round trips supported length-prefix formats under fragmentation', function (LengthPrefixFormat $format): void {
    $codec = new LengthPrefixedCodec($format, 128);
    $wire = $codec->encode('abc') . $codec->encode('hello');
    $codec->reset();

    expect($codec->push(substr($wire, 0, 1), 1))->toBe([])
        ->and($codec->push(substr($wire, 1), 1))->toBe(['abc'])
        ->and($codec->push('', 1))->toBe(['hello']);
})->with([
    LengthPrefixFormat::UINT8,
    LengthPrefixFormat::UINT16_BE,
    LengthPrefixFormat::UINT32_BE,
]);

it('rejects a declared length larger than the configured frame limit', function (): void {
    $codec = new LengthPrefixedCodec(LengthPrefixFormat::UINT16_BE, 4);

    expect(fn () => $codec->push(pack('n', 5)))->toThrow(CodecException::class);
});

it('passes raw chunks without hidden buffering', function (): void {
    $codec = new RawCodec();

    expect($codec->push('abc'))->toBe(['abc'])
        ->and($codec->bufferedBytes())->toBe(0)
        ->and($codec->encode('xyz'))->toBe('xyz');
});
