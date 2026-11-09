<?php

declare(strict_types=1);

require_once __DIR__ . '/../benchmarks/process_usage.php';

it('preserves OS usage counters and computes complete user plus system CPU time', function (): void {
    $raw = [
        'ru_utime.tv_sec' => 1, 'ru_utime.tv_usec' => 200,
        'ru_stime.tv_sec' => 2, 'ru_stime.tv_usec' => 300,
        'ru_maxrss' => 4096,
    ];
    $usage = runwireBenchmarkUsageRecord($raw);
    expect($usage)->toBe($raw)
        ->and(runwireBenchmarkCpuMicroseconds($usage))->toBe(3_000_500)
        ->and(runwireBenchmarkCpuMicroseconds(runwireBenchmarkUsage()))->toBeGreaterThanOrEqual(0);
});

it('fails incomplete CPU snapshots rather than reporting fabricated zero usage', function (): void {
    foreach ([null, false, [], ['ru_utime.tv_sec' => 1]] as $raw) {
        expect(fn() => runwireBenchmarkUsageRecord($raw))->toThrow(RuntimeException::class);
    }
    $raw = [
        'ru_utime.tv_sec' => 1, 'ru_utime.tv_usec' => 200,
        'ru_stime.tv_sec' => 2, 'ru_stime.tv_usec' => 300,
    ];
    foreach (['1', 1.5, false, null] as $invalid) {
        $raw['ru_utime.tv_sec'] = $invalid;
        expect(fn() => runwireBenchmarkUsageRecord($raw))->toThrow(RuntimeException::class);
    }
});
