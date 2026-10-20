<?php

declare(strict_types=1);

require_once __DIR__ . '/../benchmarks/r2_scheduler_pressure.php';

it('profiles bounded scheduler task, cancellation, timer and socket readiness work', function (): void {
    $result = runwireR2SchedulerPressure(2, 1, [4, 8]);

    expect($result['schema'])->toBe(1)
        ->and($result['correctness_passed'])->toBeTrue()
        ->and($result['real_network_included'])->toBeFalse()
        ->and($result['modes'])->toHaveCount(10);

    foreach ($result['modes'] as $sample) {
        expect($sample['iterations'])->toBe(2)
            ->and($sample['duration']['p99_us'])->toBeGreaterThan(0)
            ->and($sample['total_resumes'])->toBeGreaterThan(0)
            ->and($sample['peak_ready_depth'])->toBeGreaterThan(0)
            ->and($sample['peak_ready_depth'])->toBeLessThanOrEqual(1_024)
            ->and($sample['active_after'])->toBe(0)
            ->and($sample['watchers_after'])->toBe(0)
            ->and($sample['timers_after'])->toBe(0);
    }
    expect($result['modes']['cancel_sleep-8']['total_cancelled'])->toBeGreaterThan(0);
});

it('rejects invalid scheduler profiling workload sizes and iteration counts', function (): void {
    foreach ([[0, 0, [1]], [101, 0, [1]], [1, 21, [1]], [1, 0, []], [1, 0, [513]], [1, 0, [0]]] as [$iterations, $warmup, $sizes]) {
        expect(fn() => runwireR2SchedulerPressure($iterations, $warmup, $sizes))
            ->toThrow(InvalidArgumentException::class);
    }
});
