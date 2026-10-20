<?php

declare(strict_types=1);

require_once __DIR__ . '/../benchmarks/soak_runtime_telemetry.php';

/** @return list<array<string, int|float|bool>> */
function soakInternalFixture(int $heapStep = 0, int $rootsStep = 0, int $cacheStep = 0): array
{
    $samples = [];
    for ($i = 0; $i <= 120; ++$i) {
        $samples[] = [
            'elapsed_seconds' => $i * 15.0,
            'allocated_bytes' => 16_777_216 + $i * $heapStep,
            'peak_allocated_bytes' => 16_777_216,
            'gc_enabled' => true,
            'gc_runs' => $i,
            'gc_collected' => $i,
            'gc_roots' => 8 + $i * $rootsStep,
            'opcache_enabled' => true,
            'opcache_used_bytes' => 4_194_304 + $i * $cacheStep,
            'opcache_cached_scripts' => 100,
        ];
    }

    return $samples;
}

it('measures actual PHP GC and OPcache state without changing the runtime API', function (): void {
    $snapshot = soakRuntimeSnapshot();

    expect($snapshot['allocated_bytes'])->toBeGreaterThan(0)
        ->and($snapshot['gc_enabled'])->toBeBool()
        ->and($snapshot['gc_roots'])->toBeGreaterThanOrEqual(0)
        ->and($snapshot['opcache_enabled'])->toBeBool()
        ->and($snapshot['opcache_used_bytes'])->toBeGreaterThanOrEqual(0);
});

it('accepts a stable full-duration worker internal state trajectory', function (): void {
    $result = soakAnalyzeRuntimeSnapshots(soakInternalFixture());
    expect($result['status'])->toBe('passed')
        ->and($result['passed'])->toBeTrue()
        ->and($result['samples'])->toBe(121);
});

it('fails worker internal heap, GC root, and OPcache growth separately', function (): void {
    $heap = soakAnalyzeRuntimeSnapshots(soakInternalFixture(heapStep: 1_000_000));
    $roots = soakAnalyzeRuntimeSnapshots(soakInternalFixture(rootsStep: 50));
    $cache = soakAnalyzeRuntimeSnapshots(soakInternalFixture(cacheStep: 200_000));

    expect($heap['passed'])->toBeFalse()
        ->and($roots['passed'])->toBeFalse()
        ->and($cache['passed'])->toBeFalse();
});

it('rejects incomplete worker telemetry and reset GC counters', function (): void {
    $short = soakAnalyzeRuntimeSnapshots(array_slice(soakInternalFixture(), 0, 30));
    $reset = soakInternalFixture();
    $reset[40]['gc_runs'] = 0;

    expect($short['status'])->toBe('insufficient_or_invalid_evidence')
        ->and($reset)->toHaveCount(121)
        ->and(soakAnalyzeRuntimeSnapshots($reset)['passed'])->toBeFalse();
});
