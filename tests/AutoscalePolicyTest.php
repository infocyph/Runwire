<?php

declare(strict_types=1);

use Infocyph\Runwire\Supervisor\AutoscalePolicy;

it('bounds native autoscaling signals without starting a supervisor', function (): void {
    $policy = new AutoscalePolicy(
        minWorkers: 2,
        maxWorkers: 12,
        scaleInCpuRatio: 0.25,
        scaleOutCpuRatio: 0.8,
        scaleOutQueueDepth: 24,
        scaleOutP99Milliseconds: 200.0,
        scaleInSuccessfulRps: 3.0,
        sampleSeconds: 8.0,
        cooldownSeconds: 32.0,
        maxStepPerWindow: 2,
    );

    expect($policy->fixed())->toBeFalse()
        ->and($policy->effectiveMinWorkers())->toBe(2)
        ->and($policy->effectiveMaxWorkers())->toBe(12)
        ->and($policy->maxStepPerWindow)->toBe(2);
});

it('keeps explicit fixed operator worker counts within validated bounds', function (): void {
    $policy = new AutoscalePolicy(minWorkers: 2, maxWorkers: 16, workerOverride: 4);

    expect($policy->fixed())->toBeTrue()
        ->and($policy->effectiveMinWorkers())->toBe(4)
        ->and($policy->effectiveMaxWorkers())->toBe(4);
});

it('rejects invalid worker, hysteresis, quota, time and operator inputs', function (): void {
    expect(fn() => new AutoscalePolicy(minWorkers: 0))->toThrow(InvalidArgumentException::class)
        ->and(fn() => new AutoscalePolicy(minWorkers: 4, maxWorkers: 3))->toThrow(InvalidArgumentException::class)
        ->and(fn() => new AutoscalePolicy(maxWorkers: 1_025))->toThrow(InvalidArgumentException::class)
        ->and(fn() => new AutoscalePolicy(scaleInCpuRatio: 0.9, scaleOutCpuRatio: 0.7))->toThrow(InvalidArgumentException::class)
        ->and(fn() => new AutoscalePolicy(scaleOutCpuRatio: INF))->toThrow(InvalidArgumentException::class)
        ->and(fn() => new AutoscalePolicy(scaleOutQueueDepth: 0))->toThrow(InvalidArgumentException::class)
        ->and(fn() => new AutoscalePolicy(scaleOutP99Milliseconds: NAN))->toThrow(InvalidArgumentException::class)
        ->and(fn() => new AutoscalePolicy(scaleInSuccessfulRps: -1.0))->toThrow(InvalidArgumentException::class)
        ->and(fn() => new AutoscalePolicy(sampleSeconds: 0.5))->toThrow(InvalidArgumentException::class)
        ->and(fn() => new AutoscalePolicy(cooldownSeconds: 5.0))->toThrow(InvalidArgumentException::class)
        ->and(fn() => new AutoscalePolicy(maxStepPerWindow: 0))->toThrow(InvalidArgumentException::class)
        ->and(fn() => new AutoscalePolicy(maxWorkers: 4, maxStepPerWindow: 5))->toThrow(InvalidArgumentException::class)
        ->and(fn() => new AutoscalePolicy(minWorkers: 2, maxWorkers: 4, workerOverride: 5))->toThrow(InvalidArgumentException::class);
});
