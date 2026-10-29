<?php

declare(strict_types=1);

use Infocyph\Runwire\Http\Http2\Hpack\Decoder;
use Infocyph\Runwire\Http\Http2\Hpack\Encoder;
use Infocyph\Runwire\Http\Http2\Hpack\HpackException;
use Infocyph\Runwire\Http\Http2\Hpack\HuffmanCodec;
use Infocyph\Runwire\Http\Http2\Hpack\IntegerCodec;
use Infocyph\Runwire\Http\Internal\RequestHeaderValidator;

it('round-trips bounded HPACK churn without caching authorization or corrupting siblings', function (): void {
    $encoder = new Encoder(512);
    $decoder = new Decoder(512, 2_048, 16);
    for ($index = 0; $index < 256; ++$index) {
        $fields = [
            [':method', 'GET'],
            [':scheme', 'https'],
            [':authority', 'runwire.test'],
            [':path', '/runwire/' . ($index % 19)],
            ['x-dynamic', 'value-' . ($index % 11)],
            ['authorization', 'private-' . $index],
        ];
        $wire = $encoder->encode($fields);
        expect($decoder->decode($wire))->toBe($fields)
            ->and($encoder->dynamicTableBytes())->toBeLessThanOrEqual(512)
            ->and($decoder->dynamicTableBytes())->toBeLessThanOrEqual(512);
        $validated = (new RequestHeaderValidator('HTTP/2'))->request($fields);
        expect($validated->target)->toBe('/runwire/' . ($index % 19));
    }
});

it('bounds HPACK large integers, output expansion and invalid Huffman padding', function (): void {
    foreach ([0, 30, 31, 32, 127, 128, 16_384, PHP_INT_MAX] as $value) {
        $encoded = IntegerCodec::encode($value, 5);
        $offset = 0;
        expect(IntegerCodec::decode($encoded, $offset, 5))->toBe($value)
            ->and($offset)->toBe(strlen($encoded));
    }
    $codec = new HuffmanCodec();
    $value = str_repeat('runwire-hpack-pressure/', 12);
    $wire = $codec->encode($value);
    expect($codec->decode($wire, strlen($value)))->toBe($value);
    expect(fn() => $codec->decode($wire, strlen($value) - 1))
        ->toThrow(HpackException::class);
    expect(fn() => $codec->decode("\xff", 1_024))
        ->toThrow(HpackException::class);
});
