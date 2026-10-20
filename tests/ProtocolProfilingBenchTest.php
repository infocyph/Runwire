<?php

declare(strict_types=1);

require_once __DIR__ . '/../benchmarks/ProtocolCoreBench.php';

it('profiles HPACK integers and Huffman encoding with exact roundtrip outputs', function (): void {
    $bench = new ProtocolCoreBench();
    $bench->setUp();

    expect($bench->benchHttp2HpackIntegerRoundTrip())->toBe(16_384)
        ->and($bench->benchHttp2HuffmanEncode())->toBeGreaterThan(0)
        ->and($bench->benchHttp2HuffmanDecode())->toBe(strlen('https://example.test/benchmark'));
});

it('profiles complete and split HTTP2 frame parsing with equal payload sizes', function (): void {
    $bench = new ProtocolCoreBench();
    $bench->setUp();

    expect($bench->benchHttp2FrameParse())->toBe(1_024)
        ->and($bench->benchHttp2FragmentedFrameParse())->toBe(1_024);
});

it('profiles QUIC varints and QPACK integers with exact boundary values', function (): void {
    $bench = new ProtocolCoreBench();
    $bench->setUp();

    expect($bench->benchHttp3VarIntRoundTrip())->toBe(1_073_741_824)
        ->and($bench->benchHttp3QpackIntegerRoundTrip())->toBe(16_384);
});

it('profiles request-header validation across HTTP/2 and HTTP/3 with mirrored targets', function (): void {
    $bench = new ProtocolCoreBench();
    $bench->setUp();

    expect($bench->benchHttp2RequestHeaderValidation())->toBe(strlen('/benchmark?transport=h2'))
        ->and($bench->benchHttp3RequestHeaderValidation())->toBe(strlen('/benchmark?transport=h3'));
});

it('profiles actual QPACK blocked-stream recovery rather than a static encode/decode path', function (): void {
    $bench = new ProtocolCoreBench();
    $bench->setUp();

    expect($bench->benchHttp3QpackBlockedUnblock())->toBe(5);
});
