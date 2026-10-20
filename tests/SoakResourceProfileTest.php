<?php

declare(strict_types=1);

require_once __DIR__ . '/../benchmarks/soak_resource_profile.php';

/** @return list<array{elapsed:float,rss:int,virtual:int,fd:int}> */
function soakResourceTestSamples(int $rssStep = 0, int $fdStep = 0): array
{
    $samples = [];
    for ($i = 0; $i <= 120; ++$i) {
        $samples[] = [
            'elapsed' => $i * 15.0,
            'rss' => 100_000_000 + $i * $rssStep,
            'virtual' => 300_000_000,
            'fd' => 20 + $i * $fdStep,
        ];
    }
    return $samples;
}

it('accepts a full-duration stable resource profile', function (): void {
    $r = soakAnalyzeSamples(soakResourceTestSamples());
    expect($r['passed'])->toBeTrue()
        ->and($r['status'])->toBe('passed')
        ->and($r['duration_seconds'])->toBe(1800.0)
        ->and($r['samples'])->toBe(121);
});

it('rejects growing RSS and persistent descriptors', function (): void {
    $rss = soakAnalyzeSamples(soakResourceTestSamples(1_000_000));
    $fd = soakAnalyzeSamples(soakResourceTestSamples(0, 1));
    expect($rss['passed'])->toBeFalse()
        ->and($rss['status'])->toBe('resource_growth')
        ->and($fd['passed'])->toBeFalse()
        ->and($fd['status'])->toBe('resource_growth');
});

it('rejects truncated, nonmonotonic and incomplete samples', function (): void {
    $short = soakAnalyzeSamples(array_slice(soakResourceTestSamples(), 0, 20));
    $bad = soakResourceTestSamples();
    $bad[20]['elapsed'] = 10.0;
    expect($short['passed'])->toBeFalse()
        ->and($short['status'])->toBe('insufficient_evidence')
        ->and(fn() => soakAnalyzeSamples($bad))->toThrow(RuntimeException::class);
});
