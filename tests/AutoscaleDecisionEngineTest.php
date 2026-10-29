<?php

declare(strict_types=1);

use Infocyph\Runwire\Supervisor\AutoscaleDecisionEngine;
use Infocyph\Runwire\Supervisor\AutoscalePolicy;

it('proposes bounded scale-out for pressure and scale-in only during low utilization', function (): void {
    $policy = new AutoscalePolicy(minWorkers: 2, maxWorkers: 8, maxStepPerWindow: 2);
    $engine = new AutoscaleDecisionEngine();

    expect($engine->target($policy, 4, 0.85, 0, 100, 20, 40))->toBe(6)
        ->and($engine->target($policy, 7, 0.85, 0, 100, 20, 40))->toBe(8)
        ->and($engine->target($policy, 4, 0.1, 20, 0, 0, 40))->toBe(6)
        ->and($engine->target($policy, 4, 0.1, 0, 0.1, 1, 40))->toBe(2)
        ->and($engine->target($policy, 2, 0.1, 0, 0, 0, 40))->toBe(2)
        ->and($engine->target($policy, 4, 0.5, 0, 100, 5, 40))->toBe(4);
});

it('respects cooldown, recovery bounds and explicit fixed operator overrides', function (): void {
    $policy = new AutoscalePolicy(minWorkers: 2, maxWorkers: 8, maxStepPerWindow: 2);
    $fixed = new AutoscalePolicy(minWorkers: 2, maxWorkers: 8, workerOverride: 3);
    $engine = new AutoscaleDecisionEngine();

    expect($engine->target($policy, 4, 0.9, 30, 100, 600, 10))->toBe(4)
        ->and($engine->target($policy, 1, 0.9, 30, 100, 600, 10))->toBe(2)
        ->and($engine->target($policy, 9, 0.1, 0, 0, 0, 10))->toBe(8)
        ->and($engine->target($fixed, 7, 0.9, 30, 100, 600, 1))->toBe(3);
});

it('rejects invalid observations without suggesting unsafe worker counts', function (): void {
    $engine = new AutoscaleDecisionEngine();
    $policy = new AutoscalePolicy();

    expect(fn() => $engine->target($policy, 0, 0.5, 0, 0, 0, 30))->toThrow(InvalidArgumentException::class)
        ->and(fn() => $engine->target($policy, 4, NAN, 0, 0, 0, 30))->toThrow(InvalidArgumentException::class)
        ->and(fn() => $engine->target($policy, 4, 1.2, 0, 0, 0, 30))->toThrow(InvalidArgumentException::class)
        ->and(fn() => $engine->target($policy, 4, 0.5, -1, 0, 0, 30))->toThrow(InvalidArgumentException::class)
        ->and(fn() => $engine->target($policy, 4, 0.5, 0, -1, 0, 30))->toThrow(InvalidArgumentException::class)
        ->and(fn() => $engine->target($policy, 4, 0.5, 0, 0, INF, 30))->toThrow(InvalidArgumentException::class)
        ->and(fn() => $engine->target($policy, 4, 0.5, 0, 0, 0, -1))->toThrow(InvalidArgumentException::class);
});
