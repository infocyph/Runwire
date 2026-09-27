<?php

declare(strict_types=1);

use Infocyph\Runwire\Http\Internal\AdaptiveLoadController;
use Infocyph\Runwire\Http\Internal\AdaptiveLoadSample;
use Infocyph\Runwire\Http\Internal\AdaptiveLoadState;
use InvalidArgumentException;

it('normalizes adaptive load counters without exceeding basis-point bounds', function (): void {
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
});

it('moves through adaptive states only after smoothed sustained load', function (): void {
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
});

it('does not flap on isolated load spikes and promotes sustained transport pressure', function (): void {
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
});

it('resets adaptive state and smoothed score deterministically', function (): void {
    $controller = new AdaptiveLoadController();
    foreach (range(1, 3) as $_) {
        $controller->observe(new AdaptiveLoadSample(true, 10_000, 10_000));
    }

    expect($controller->state())->toBe(AdaptiveLoadState::THROUGHPUT)
        ->and($controller->smoothedScore())->toBeGreaterThan(0);

    $controller->reset();

    expect($controller->state())->toBe(AdaptiveLoadState::BALANCED)
        ->and($controller->smoothedScore())->toBe(0);
});
