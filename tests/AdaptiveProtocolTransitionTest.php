<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Tests;

use Infocyph\Runwire\Http\AdaptiveProtocolPolicy;
use Infocyph\Runwire\Http\Enum\AdaptiveLoadState;
use Infocyph\Runwire\Http\Enum\AdaptivePolicyMode;
use Infocyph\Runwire\Http\Http1\Http1Limits;
use Infocyph\Runwire\Http\Http2\Http2Limits;
use Infocyph\Runwire\Http\Http2\Internal\AdaptiveResponseStrategy as Http2AdaptiveResponseStrategy;
use Infocyph\Runwire\Http\Internal\AdaptiveLoadSample;

it('holds HTTP2 state through single-sample bursts and switches only after sustained crossover', function (): void {
    runwireAdaptiveProtocolTransitionCase01();
});

it('keeps every adaptive work budget inside configured protocol ceilings', function (): void {
    runwireAdaptiveProtocolTransitionCase03();
});

it('keeps hard resource limits independent from adaptive policy', function (): void {
    runwireAdaptiveProtocolTransitionCase04();
});

/**
 * @throws \Throwable
 */
function runwireAdaptiveProtocolTransitionCase01(): void
{
    $strategy = new Http2AdaptiveResponseStrategy(
        policy: new AdaptiveProtocolPolicy(
            lowWatermarkBasisPoints: 2_000,
            highWatermarkBasisPoints: 6_000,
            transitionSamples: 2,
            ewmaNumerator: 1,
            ewmaDenominator: 1,
        ),
    );
    $low = new AdaptiveLoadSample(false, 0, 0);
    $high = new AdaptiveLoadSample(false, 10_000, 10_000);

    expect($strategy->wireLimit($low))->toBe(1_024)
        ->and($strategy->wireLimit($low))->toBe(1_024)
        ->and($strategy->state())->toBe(AdaptiveLoadState::LATENCY);

    foreach (range(1, 4) as $_) {
        expect($strategy->wireLimit($high))->toBe(1_024);
        expect($strategy->wireLimit($low))->toBe(1_024);
    }

    expect($strategy->state())->toBe(AdaptiveLoadState::LATENCY)
        ->and($strategy->wireLimit($high))->toBe(1_024)
        ->and($strategy->wireLimit($high))->toBe(256)
        ->and($strategy->state())->toBe(AdaptiveLoadState::THROUGHPUT)
        ->and($strategy->wireLimit($low))->toBe(1_024)
        ->and($strategy->wireLimit($low))->toBe(1_024)
        ->and($strategy->state())->toBe(AdaptiveLoadState::LATENCY);
}

/**
 * @throws \Throwable
 */
function runwireAdaptiveProtocolTransitionCase03(): void
{
    $samples = [
        new AdaptiveLoadSample(false, 0, 0),
        new AdaptiveLoadSample(false, 5_000, 5_000),
        new AdaptiveLoadSample(false, 10_000, 10_000),
        new AdaptiveLoadSample(true, 10_000, 10_000),
    ];

    foreach (AdaptivePolicyMode::cases() as $mode) {
        $policy = new AdaptiveProtocolPolicy(
            mode: $mode,
            transitionSamples: 1,
            ewmaNumerator: 1,
            ewmaDenominator: 1,
        );
        $http2 = new Http2AdaptiveResponseStrategy(policy: $policy);

        foreach ($samples as $sample) {
            $wireLimit = $http2->wireLimit($sample);
            expect($wireLimit)->toBeGreaterThanOrEqual(1)
                ->and($wireLimit)->toBeLessThanOrEqual(1_024);
        }
    }
}

/**
 * @throws \Throwable
 */
function runwireAdaptiveProtocolTransitionCase04(): void
{
    $throughput = new AdaptiveProtocolPolicy(mode: AdaptivePolicyMode::THROUGHPUT);

    $http1 = new Http1Limits(
        maxBodyBytes: 12_345,
        maxPendingBodyBytes: 8_192,
        bodyLowWatermarkBytes: 2_048,
        bodyHighWatermarkBytes: 4_096,
        adaptive: $throughput,
    );
    $http2 = new Http2Limits(
        maxConcurrentStreams: 7,
        maxStreamsPerConnection: 77,
        adaptive: $throughput,
    );
    expect($http1->maxBodyBytes)->toBe(12_345)
        ->and($http1->maxPendingBodyBytes)->toBe(8_192)
        ->and($http2->maxConcurrentStreams)->toBe(7)
        ->and($http2->maxStreamsPerConnection)->toBe(77);
}
