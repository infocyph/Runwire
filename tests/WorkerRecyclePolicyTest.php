<?php

declare(strict_types=1);

use Infocyph\Runwire\Runtime\Internal\WorkerRecycleState;
use Infocyph\Runwire\Supervisor\WorkerRecyclePolicy;

it('defaults worker recycle thresholds to disabled while keeping graceful drain bounded', function (): void {
    $policy = new WorkerRecyclePolicy();

    expect($policy->enabled())->toBeFalse()
        ->and($policy->maxRequests)->toBe(0)
        ->and($policy->maxLifetimeSeconds)->toBe(0)
        ->and($policy->maxMemoryBytes)->toBe(0)
        ->and($policy->jitterRequests)->toBe(0)
        ->and($policy->jitterSeconds)->toBe(0)
        ->and($policy->gracefulTimeoutSeconds)->toBe(10.0);
});

it('applies deterministic bounded request and lifetime jitter', function (): void {
    $policy = new WorkerRecyclePolicy(
        maxRequests: 100,
        maxLifetimeSeconds: 300,
        jitterRequests: 25,
        jitterSeconds: 60,
    );
    $first = new WorkerRecycleState($policy, seed: 42, startedAtNs: 1_000_000_000);
    $second = new WorkerRecycleState($policy, seed: 42, startedAtNs: 1_000_000_000);

    expect($first->effectiveMaxRequests())->toBe($second->effectiveMaxRequests())
        ->and($first->effectiveMaxRequests())->toBeGreaterThanOrEqual(100)
        ->and($first->effectiveMaxRequests())->toBeLessThanOrEqual(125)
        ->and($first->effectiveMaxLifetimeSeconds())->toBe($second->effectiveMaxLifetimeSeconds())
        ->and($first->effectiveMaxLifetimeSeconds())->toBeGreaterThanOrEqual(300)
        ->and($first->effectiveMaxLifetimeSeconds())->toBeLessThanOrEqual(360);
});

it('recycles only after the effective logical request budget', function (): void {
    $state = new WorkerRecycleState(
        new WorkerRecyclePolicy(maxRequests: 3),
        seed: 1,
        startedAtNs: 0,
    );

    expect($state->recordRequestCompleted(nowNs: 1, currentMemoryBytes: 1, peakMemoryBytes: 1))->toBeFalse()
        ->and($state->recordRequestCompleted(nowNs: 2, currentMemoryBytes: 1, peakMemoryBytes: 1))->toBeFalse()
        ->and($state->recordRequestCompleted(nowNs: 3, currentMemoryBytes: 1, peakMemoryBytes: 1))->toBeTrue()
        ->and($state->requestsTotal())->toBe(3);
});

it('supports accounting without enforcing the request limit for host-native recycling', function (): void {
    $state = new WorkerRecycleState(
        new WorkerRecyclePolicy(maxRequests: 1),
        seed: 1,
        startedAtNs: 0,
    );

    expect($state->recordRequestCompleted(
        enforceRequestLimit: false,
        nowNs: 1,
        currentMemoryBytes: 1,
        peakMemoryBytes: 1,
    ))->toBeFalse()
        ->and($state->requestsTotal())->toBe(1);
});

it('uses current allocated memory as a soft recycle trigger and retains peak memory separately', function (): void {
    $state = new WorkerRecycleState(
        new WorkerRecyclePolicy(maxMemoryBytes: 1_024),
        seed: 1,
        startedAtNs: 0,
    );

    expect($state->recordRequestCompleted(
        nowNs: 1,
        currentMemoryBytes: 1_023,
        peakMemoryBytes: 4_096,
    ))->toBeFalse()
        ->and($state->currentMemoryBytes())->toBe(1_023)
        ->and($state->peakMemoryBytes())->toBe(4_096)
        ->and($state->recordRequestCompleted(
            nowNs: 2,
            currentMemoryBytes: 1_024,
            peakMemoryBytes: 4_096,
        ))->toBeTrue();
});

it('evaluates lifetime recycling on monotonic safe request boundaries', function (): void {
    $state = new WorkerRecycleState(
        new WorkerRecyclePolicy(maxLifetimeSeconds: 2),
        seed: 1,
        startedAtNs: 1_000_000_000,
    );

    expect($state->recordRequestCompleted(
        nowNs: 2_999_999_999,
        currentMemoryBytes: 1,
        peakMemoryBytes: 1,
    ))->toBeFalse()
        ->and($state->recordRequestCompleted(
            nowNs: 3_000_000_000,
            currentMemoryBytes: 1,
            peakMemoryBytes: 1,
        ))->toBeTrue();
});

it('rejects meaningless or unsafe recycle policy values', function (): void {
    expect(static fn() => new WorkerRecyclePolicy(maxRequests: -1))->toThrow(InvalidArgumentException::class)
        ->and(static fn() => new WorkerRecyclePolicy(maxRequests: 0, jitterRequests: 1))->toThrow(InvalidArgumentException::class)
        ->and(static fn() => new WorkerRecyclePolicy(maxLifetimeSeconds: 0, jitterSeconds: 1))->toThrow(InvalidArgumentException::class)
        ->and(static fn() => new WorkerRecyclePolicy(maxMemoryBytes: -1))->toThrow(InvalidArgumentException::class)
        ->and(static fn() => new WorkerRecyclePolicy(gracefulTimeoutSeconds: 0))->toThrow(InvalidArgumentException::class);
});
