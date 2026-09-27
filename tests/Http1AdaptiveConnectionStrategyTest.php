<?php

declare(strict_types=1);

use Infocyph\Runwire\Http\Enum\AdaptiveLoadState;
use Infocyph\Runwire\Http\Http1\Internal\AdaptiveConnectionStrategy;
use Infocyph\Runwire\Http\Internal\AdaptiveLoadSample;

it('keeps NODELAY for normal H1 load and disables it after sustained worker pressure', function (): void {
    $strategy = new AdaptiveConnectionStrategy();
    $low = new AdaptiveLoadSample(false, 0, 0);

    expect($strategy->tcpNoDelay($low))->toBeTrue();
    $strategy->tcpNoDelay($low);
    expect($strategy->tcpNoDelay($low))->toBeTrue()
        ->and($strategy->state())->toBe(AdaptiveLoadState::LATENCY);

    $high = new AdaptiveConnectionStrategy();
    $pressured = new AdaptiveLoadSample(true, 10_000, 10_000);
    $result = true;
    foreach (range(1, 3) as $_) {
        $result = $high->tcpNoDelay($pressured);
    }

    expect($high->state())->toBe(AdaptiveLoadState::THROUGHPUT)
        ->and($result)->toBeFalse();
});

it('treats sustained H1 write backlog as a throughput signal before connection count saturates', function (): void {
    $strategy = new AdaptiveConnectionStrategy();
    $backlogged = AdaptiveLoadSample::fromCounters(
        pressured: false,
        queuedBytes: 262_144,
        queueCapacityBytes: 262_144,
        activeWork: 1,
        activeCapacity: 256,
    );

    foreach (range(1, 3) as $_) {
        $result = $strategy->tcpNoDelay($backlogged);
    }

    expect($strategy->state())->toBe(AdaptiveLoadState::THROUGHPUT)
        ->and($result)->toBeFalse();
});
