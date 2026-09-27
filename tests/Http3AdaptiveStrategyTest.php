<?php

declare(strict_types=1);

use Infocyph\Runwire\Http\Enum\AdaptiveLoadState;
use Infocyph\Runwire\Http\Http3\Internal\AdaptivePollStrategy;
use Infocyph\Runwire\Http\Http3\Internal\AdaptivePumpStrategy;
use Infocyph\Runwire\Http\Http3\Internal\AdaptiveResponseStrategy;
use Infocyph\Runwire\Http\Internal\AdaptiveLoadSample;

it('adapts HTTP3 write effort without exceeding the configured maximum', function (): void {
    $strategy = new AdaptiveResponseStrategy();
    $low = new AdaptiveLoadSample(false, 0, 0);

    expect($strategy->writeLimit($low, 128))->toBe(64);
    $strategy->writeLimit($low, 128);
    expect($strategy->writeLimit($low, 128))->toBe(32)
        ->and($strategy->state())->toBe(AdaptiveLoadState::LATENCY);

    $throughput = new AdaptiveResponseStrategy();
    $high = new AdaptiveLoadSample(true, 10_000, 10_000);
    foreach (range(1, 3) as $_) {
        $limit = $throughput->writeLimit($high, 128);
    }

    expect($throughput->state())->toBe(AdaptiveLoadState::THROUGHPUT)
        ->and($limit)->toBe(128);
});

it('adapts HTTP3 read effort while keeping acceptance stable under sustained load', function (): void {
    $strategy = new AdaptivePumpStrategy();

    foreach (range(1, 3) as $_) {
        $strategy->observe(0, 0, 100);
    }
    expect($strategy->state())->toBe(AdaptiveLoadState::LATENCY)
        ->and($strategy->acceptLimit(64))->toBe(64)
        ->and($strategy->readLimit(256))->toBe(64);

    $high = new AdaptivePumpStrategy();
    foreach (range(1, 3) as $_) {
        $high->observe(100, 100, 100);
    }
    expect($high->state())->toBe(AdaptiveLoadState::THROUGHPUT)
        ->and($high->acceptLimit(64))->toBe(64)
        ->and($high->readLimit(256))->toBe(256)
        ->and($high->byteLimit(262_144))->toBe(262_144);
});

it('adapts HTTP3 poll timing only for idle listeners and pending handshakes', function (): void {
    expect(AdaptivePollStrategy::timeout(0.0, true, 0, 0))->toBe(0.0)
        ->and(AdaptivePollStrategy::timeout(0.05, true, 0, 0))->toBe(0.2)
        ->and(AdaptivePollStrategy::timeout(0.05, true, 0, 1))->toBe(0.01)
        ->and(AdaptivePollStrategy::timeout(0.05, true, 1, 0))->toBe(0.05)
        ->and(AdaptivePollStrategy::timeout(null, true, 0, 0))->toBeNull();
});
