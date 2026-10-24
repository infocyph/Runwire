<?php

declare(strict_types=1);

require_once __DIR__ . '/../benchmarks/r2_mixed_scheduler_soak.php';

it('measures bounded mixed timer, CPU and actual socket pressure across increasing loads', function (): void {
    $report = runwireR2MixedSchedulerSoak(0.15);

    expect($report['schema'])->toBe(1)
        ->and($report['tier_count'])->toBe(3)
        ->and($report['correctness_passed'])->toBeTrue()
        ->and($report['real_socket_io'])->toBeTrue();

    foreach ($report['tiers'] as $tier) {
        expect($tier['socket_reads'])->toBeGreaterThanOrEqual(4)
            ->and($tier['readable_p99_ms'])->toBeLessThanOrEqual(500.0)
            ->and($tier['cpu_callbacks'])->toBeGreaterThan(0)
            ->and($tier['pending_socket_bytes'])->toBeLessThanOrEqual(1)
            ->and($tier['max_pending_bytes'])->toBeLessThanOrEqual(8)
            ->and($tier['watchers_after'])->toBe(0)
            ->and($tier['timers_after'])->toBe(0)
            ->and($tier['deferred_after'])->toBe(0)
            ->and($tier['correctness_passed'])->toBeTrue();
    }
});

it('rejects unsafe mixed scheduler pressure configurations', function (): void {
    expect(fn() => r2MixedPressureTier(0, 1.0))->toThrow(InvalidArgumentException::class)
        ->and(fn() => r2MixedPressureTier(193, 1.0))->toThrow(InvalidArgumentException::class)
        ->and(fn() => r2MixedPressureTier(4, 0.0))->toThrow(InvalidArgumentException::class)
        ->and(fn() => r2MixedPercentile([], 0.99))->toThrow(InvalidArgumentException::class);
});
