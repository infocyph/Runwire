<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/benchmarks/r0_host_profile.php';

it('profiles the real hosted normalization, writer and finalization sequence with bounded samples', function (): void {
    $result = runwireR0HostProfile(16, 3);

    expect($result['correctness_passed'])->toBeTrue()
        ->and($result['completed'])->toBe(16)
        ->and($result['iterations'])->toBe(16)
        ->and($result['warmup_iterations'])->toBe(3)
        ->and($result['host_network_included'])->toBeFalse();

    foreach (['request_normalization', 'writer_allocation', 'dispatch_and_finalization', 'total'] as $phase) {
        expect($result['phases'][$phase]['p50_us'])->toBeGreaterThan(0)
            ->and($result['phases'][$phase]['p99_us'])->toBeGreaterThan(0)
            ->and($result['phases'][$phase]['mean_us'])->toBeGreaterThan(0);
    }
});

it('rejects unbounded or nonsensical R0 profiling sample counts', function (): void {
    foreach ([[0, 0], [1, -1], [100_001, 0], [10, 10_001]] as [$samples, $warmup]) {
        expect(fn() => runwireR0HostProfile($samples, $warmup))
            ->toThrow(InvalidArgumentException::class);
    }
});
