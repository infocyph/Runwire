<?php

declare(strict_types=1);

use Infocyph\Runwire\Http\Http2\Hpack\Decoder;
use Infocyph\Runwire\Http\Http2\Hpack\Encoder;
use Infocyph\Runwire\Http\Http2\Hpack\HpackException;
use Infocyph\Runwire\Http\Http2\Hpack\HuffmanCodec;

it('matches RFC HPACK request examples without Huffman coding', function (): void {
    $decoder = new Decoder();

    expect($decoder->decode(hex2bin('828684410f7777772e6578616d706c652e636f6d')))->toBe([
        [':method', 'GET'],
        [':scheme', 'http'],
        [':path', '/'],
        [':authority', 'www.example.com'],
    ])->and($decoder->decode(hex2bin('828684be58086e6f2d6361636865')))->toBe([
        [':method', 'GET'],
        [':scheme', 'http'],
        [':path', '/'],
        [':authority', 'www.example.com'],
        ['cache-control', 'no-cache'],
    ])->and($decoder->decode(hex2bin('828785bf400a637573746f6d2d6b65790576616c7565')))->toBe([
        [':method', 'GET'],
        [':scheme', 'https'],
        [':path', '/index.html'],
        [':authority', 'www.example.com'],
        ['custom-key', 'value'],
    ]);
});

it('matches RFC Huffman values and decodes a Huffman request example', function (): void {
    $codec = new HuffmanCodec();

    foreach ([
        'www.example.com' => 'f1e3c2e5f23a6ba0ab90f4ff',
        'no-cache' => 'a8eb10649cbf',
        'custom-key' => '25a849e95ba97d7f',
        'custom-value' => '25a849e95bb8e8b4bf',
    ] as $plain => $hex) {
        expect(bin2hex($codec->encode($plain)))->toBe($hex)
            ->and($codec->decode(hex2bin($hex), 1_024))->toBe($plain);
    }

    $headers = (new Decoder())->decode(hex2bin('828684418cf1e3c2e5f23a6ba0ab90f4ff'));
    expect($headers[3])->toBe([':authority', 'www.example.com']);
});

it('round-trips indexed, dynamic and never-indexed fields', function (): void {
    $encoder = new Encoder();
    $decoder = new Decoder();
    $headers = [
        [':status', '200'],
        ['content-type', 'text/plain'],
        ['x-custom', 'reused'],
        ['authorization', 'secret'],
    ];

    $first = $encoder->encode($headers);
    expect($decoder->decode($first))->toBe($headers);

    $second = $encoder->encode($headers);
    expect($decoder->decode($second))->toBe($headers)
        ->and(strlen($second))->toBeLessThan(strlen($first));
});

it('enforces decoded header-list and Huffman output limits', function (): void {
    expect(fn () => (new Decoder(64, 40, 10))->decode(
        hex2bin('400a637573746f6d2d6b65790d637573746f6d2d686561646572'),
    ))->toThrow(HpackException::class);

    expect(fn () => (new HuffmanCodec())->decode("\xff", 1_024))
        ->toThrow(HpackException::class);
});

it('rejects dynamic table updates after a header representation', function (): void {
    expect(fn () => (new Decoder())->decode("\x82\x20"))
        ->toThrow(HpackException::class);
});
