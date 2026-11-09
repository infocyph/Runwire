<?php

declare(strict_types=1);

require_once __DIR__ . '/../benchmarks/ProtocolCoreBench.php';

it('executes real protocol benchmark fixtures through their decoders and validators', function (): void {
    $benchmark = new ProtocolCoreBench();

    expect($benchmark->benchHttp1HeadValidation())->toBe(512)
        ->and($benchmark->benchHttp2FrameParse())->toBe(1_024)
        ->and($benchmark->benchHttp2FragmentedFrameParse())->toBe(1_024)
        ->and($benchmark->benchHttp2HpackDecode())->toBe(7)
        ->and($benchmark->benchHttp2HpackEncode())->toBeGreaterThan(0)
        ->and($benchmark->benchHttp2HuffmanDecode())->toBe(strlen('https://example.test/benchmark'))
        ->and($benchmark->benchHttp3FrameDecode())->toBe(1_024)
        ->and($benchmark->benchHttp3QpackStaticDecode())->toBe(7)
        ->and($benchmark->benchHttp3QpackStaticEncode())->toBeGreaterThan(0);
});
