<?php

declare(strict_types=1);

use Infocyph\Runwire\Supervisor\Internal\AutoscaleHysteresis;

it('scales out after sustained saturation and respects monotonic cooldown without oscillating', function (): void {
    $policy = new AutoscaleHysteresis(
        minWorkers: 2,
        maxWorkers: 4,
        scaleOutSamples: 3,
        scaleInSamples: 4,
        cooldownSeconds: 2.0,
    );
    $now = 0;
    expect($policy->propose(2, 2, 3, $now))->toBe(2)
        ->and($policy->propose(2, 2, 3, $now + 100_000_000))->toBe(2)
        ->and($policy->propose(2, 2, 3, $now + 200_000_000))->toBe(3);
    for ($index = 0; $index < 100; ++$index) {
        expect($policy->propose(3, 0, 0, $now + 300_000_000 + $index * 10_000_000))->toBe(3);
    }
    expect($policy->propose(3, 3, 2, 2_100_000_000))->toBe(3)
        ->and($policy->propose(3, 3, 2, 2_150_000_000))->toBe(3)
        ->and($policy->propose(3, 3, 2, 2_200_000_000))->toBe(4);
    expect($policy->propose(4, 4, 5, 5_000_000_000))->toBe(4);
});

it('requires prolonged idle signals, refuses scale-in with backlog, and never drops below the minimum', function (): void {
    $policy = new AutoscaleHysteresis(
        minWorkers: 1,
        maxWorkers: 5,
        scaleOutSamples: 2,
        scaleInSamples: 3,
        cooldownSeconds: 0.0,
    );
    expect($policy->propose(3, 0, 1, 100))->toBe(3)
        ->and($policy->propose(3, 0, 0, 200))->toBe(3)
        ->and($policy->propose(3, 0, 0, 300))->toBe(3)
        ->and($policy->propose(3, 0, 0, 400))->toBe(2)
        ->and($policy->propose(2, 0, 0, 500))->toBe(2)
        ->and($policy->propose(2, 0, 0, 600))->toBe(2)
        ->and($policy->propose(2, 0, 0, 700))->toBe(1);
    for ($i = 0; $i < 200; ++$i) {
        expect($policy->propose(1, 0, 0, 1_000 + $i))->toBe(1);
    }
});

it('validates bounds and clears stale hysteresis observations across a supervisor reload', function (): void {
    expect(fn() => new AutoscaleHysteresis(0, 4))->toThrow(InvalidArgumentException::class)
        ->and(fn() => new AutoscaleHysteresis(4, 2))->toThrow(InvalidArgumentException::class)
        ->and(fn() => new AutoscaleHysteresis(1, 1, scaleOutRatio: 0.5, scaleInRatio: 0.5))
        ->toThrow(InvalidArgumentException::class);

    $policy = new AutoscaleHysteresis(1, 4, scaleOutSamples: 2, scaleInSamples: 2);
    expect($policy->propose(1, 1, 0, 100))->toBe(1);
    $policy->reset();
    expect($policy->propose(1, 1, 0, 200))->toBe(1)
        ->and($policy->propose(1, 1, 0, 300))->toBe(2);
    expect(fn() => $policy->propose(2, 3, 0, 400))->toThrow(InvalidArgumentException::class);
    expect(fn() => $policy->propose(2, 0, -1, 400))->toThrow(InvalidArgumentException::class);
});
