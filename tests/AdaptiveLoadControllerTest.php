<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Tests;

use Infocyph\Runwire\Http\Enum\AdaptiveLoadState;
use Infocyph\Runwire\Http\Internal\AdaptiveLoadController;
use Infocyph\Runwire\Http\Internal\AdaptiveLoadSample;
use InvalidArgumentException;

it('normalizes adaptive load counters without exceeding basis-point bounds', function (): void {
    runwireAdaptiveLoadControllerCase01();
});

it('moves through adaptive states only after smoothed sustained load', function (): void {
    runwireAdaptiveLoadControllerCase02();
});

it('does not flap on isolated load spikes and promotes sustained transport pressure', function (): void {
    runwireAdaptiveLoadControllerCase03();
});

it('resets adaptive state and smoothed score deterministically', function (): void {
    runwireAdaptiveLoadControllerCase04();
});


it('keeps the largest safe EWMA ratio in integer range', function (): void {
    runwireAdaptiveLoadControllerCase05();
});

/**
 * @throws \Throwable
 */
function runwireAdaptiveLoadControllerCase01(): void
{
    $sample = AdaptiveLoadSample::fromCounters(
        pressured: false,
        queuedBytes: 750,
        queueCapacityBytes: 1_000,
        activeWork: 150,
        activeCapacity: 100,
    );

    expect($sample->backlogBasisPoints)->toBe(7_500)
        ->and($sample->activeBasisPoints)->toBe(10_000)
        ->and($sample->score())->toBe(8_250);

    expect(fn() => AdaptiveLoadSample::fromCounters(false, -1, 10, 0, 1))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn() => AdaptiveLoadSample::fromCounters(false, 0, 0, 0, 1))
        ->toThrow(InvalidArgumentException::class);
}

/**
 * @throws \Throwable
 */
function runwireAdaptiveLoadControllerCase02(): void
{
    $controller = new AdaptiveLoadController();
    $low = new AdaptiveLoadSample(false, 0, 0);
    $high = new AdaptiveLoadSample(false, 10_000, 10_000);
    $middle = new AdaptiveLoadSample(false, 5_000, 5_000);

    expect($controller->state())->toBe(AdaptiveLoadState::BALANCED);

    foreach (range(1, 3) as $_) {
        $controller->observe($low);
    }
    expect($controller->state())->toBe(AdaptiveLoadState::LATENCY);

    foreach (range(1, 4) as $_) {
        $controller->observe($high);
    }
    expect($controller->state())->toBe(AdaptiveLoadState::THROUGHPUT);

    foreach (range(1, 4) as $_) {
        $controller->observe($middle);
    }
    expect($controller->state())->toBe(AdaptiveLoadState::BALANCED);

    foreach (range(1, 4) as $_) {
        $controller->observe($low);
    }
    expect($controller->state())->toBe(AdaptiveLoadState::LATENCY);
}

/**
 * @throws \Throwable
 */
function runwireAdaptiveLoadControllerCase03(): void
{
    $controller = new AdaptiveLoadController();
    $low = new AdaptiveLoadSample(false, 0, 0);
    foreach (range(1, 3) as $_) {
        $controller->observe($low);
    }

    $controller->observe(new AdaptiveLoadSample(false, 10_000, 10_000));
    expect($controller->state())->toBe(AdaptiveLoadState::LATENCY);

    $pressured = new AdaptiveLoadSample(true, 0, 0);
    foreach (range(1, 3) as $_) {
        $controller->observe($pressured);
    }

    expect($controller->state())->toBe(AdaptiveLoadState::THROUGHPUT);
}

/**
 * @throws \Throwable
 */
function runwireAdaptiveLoadControllerCase04(): void
{
    $controller = new AdaptiveLoadController();
    foreach (range(1, 3) as $_) {
        $controller->observe(new AdaptiveLoadSample(true, 10_000, 10_000));
    }

    expect($controller->state())->toBe(AdaptiveLoadState::THROUGHPUT)
        ->and($controller->smoothedScore())->toBeGreaterThan(0);

    $controller->reset();

    expect($controller->state())->toBe(AdaptiveLoadState::BALANCED)
        ->and($controller->smoothedScore())->toBe(0);
}

/**
 * @throws \Throwable
 */
function runwireAdaptiveLoadControllerCase05(): void
{
    $maximum = intdiv(PHP_INT_MAX, AdaptiveLoadSample::MAX_BASIS_POINTS);
    $controller = new AdaptiveLoadController(
        transitionSamples: 1,
        ewmaNumerator: $maximum,
        ewmaDenominator: $maximum,
    );

    $controller->observe(new AdaptiveLoadSample(false, 0, 0));
    $controller->observe(new AdaptiveLoadSample(false, 10_000, 10_000));

    expect($controller->smoothedScore())->toBe(10_000)
        ->and(fn() => new AdaptiveLoadController(
            ewmaNumerator: PHP_INT_MAX,
            ewmaDenominator: PHP_INT_MAX,
        ))->toThrow(InvalidArgumentException::class);
}
