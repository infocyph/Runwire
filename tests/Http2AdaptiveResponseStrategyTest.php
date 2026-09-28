<?php

declare(strict_types=1);

use Infocyph\Runwire\Http\Enum\AdaptiveLoadState;
use Infocyph\Runwire\Http\Http2\Internal\AdaptiveResponseStrategy;
use Infocyph\Runwire\Http\Internal\AdaptiveLoadSample;

it('adapts HTTP2 initial-response wire intent from sustained protocol load', function (): void {
    $strategy = new AdaptiveResponseStrategy();
    $low = new AdaptiveLoadSample(false, 0, 0);

    expect($strategy->wireLimit($low))->toBe(1_024);
    $strategy->wireLimit($low);
    expect($strategy->wireLimit($low))->toBe(1_024)
        ->and($strategy->state())->toBe(AdaptiveLoadState::LATENCY);

    $throughputStrategy = new AdaptiveResponseStrategy();
    $high = AdaptiveLoadSample::fromCounters(
        pressured: false,
        queuedBytes: 0,
        queueCapacityBytes: 262_144,
        activeWork: 32,
        activeCapacity: 32,
    );
    foreach (range(1, 3) as $_) {
        $limit = $throughputStrategy->wireLimit($high);
    }

    expect($throughputStrategy->state())->toBe(AdaptiveLoadState::THROUGHPUT)
        ->and($limit)->toBe(256);
});

it('keeps HTTP2 adaptive budgets ordered and bounded', function (): void {
    expect(fn() => new AdaptiveResponseStrategy(
        latencyWireBytes: 512,
        balancedWireBytes: 768,
        throughputWireBytes: 256,
    ))->toThrow(InvalidArgumentException::class);
});
