<?php

declare(strict_types=1);

use Infocyph\Runwire\Http\AdaptiveProtocolPolicy;
use Infocyph\Runwire\Http\Enum\AdaptiveLoadState;
use Infocyph\Runwire\Http\Enum\AdaptivePolicyMode;
use Infocyph\Runwire\Http\Http1\Http1Limits;
use Infocyph\Runwire\Http\Http2\Http2Limits;
use Infocyph\Runwire\Http\Http2\Internal\AdaptiveResponseStrategy as Http2AdaptiveResponseStrategy;
use Infocyph\Runwire\Http\Http3\Http3Limits;
use Infocyph\Runwire\Http\Http3\Http3Options;
use Infocyph\Runwire\Http\Http3\Internal\AdaptivePumpStrategy;
use Infocyph\Runwire\Http\Http3\Internal\AdaptiveResponseStrategy as Http3AdaptiveResponseStrategy;
use Infocyph\Runwire\Http\Internal\AdaptiveLoadSample;

it('holds HTTP2 state through single-sample bursts and switches only after sustained crossover', function (): void {
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

    expect($strategy->wireLimit($low))->toBe(512)
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
        ->and($strategy->wireLimit($low))->toBe(256)
        ->and($strategy->wireLimit($low))->toBe(1_024)
        ->and($strategy->state())->toBe(AdaptiveLoadState::LATENCY);
});

it('adapts HTTP3 pump effort without flapping on isolated high-load samples', function (): void {
    $strategy = new AdaptivePumpStrategy(
        policy: new AdaptiveProtocolPolicy(
            lowWatermarkBasisPoints: 2_000,
            highWatermarkBasisPoints: 6_000,
            transitionSamples: 2,
            ewmaNumerator: 1,
            ewmaDenominator: 1,
        ),
    );

    $strategy->observe(0, 0, 100);
    $strategy->observe(0, 0, 100);
    expect($strategy->state())->toBe(AdaptiveLoadState::LATENCY)
        ->and($strategy->acceptLimit(64))->toBe(64)
        ->and($strategy->readLimit(256))->toBe(64);

    foreach (range(1, 4) as $_) {
        $strategy->observe(100, 100, 100);
        expect($strategy->state())->toBe(AdaptiveLoadState::LATENCY);
        $strategy->observe(0, 0, 100);
        expect($strategy->state())->toBe(AdaptiveLoadState::LATENCY);
    }

    $strategy->observe(100, 100, 100);
    $strategy->observe(100, 100, 100);
    expect($strategy->state())->toBe(AdaptiveLoadState::THROUGHPUT)
        ->and($strategy->acceptLimit(64))->toBe(16)
        ->and($strategy->readLimit(256))->toBe(256);
});

it('keeps every adaptive work budget inside configured protocol ceilings', function (): void {
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
        $http3Response = new Http3AdaptiveResponseStrategy(policy: $policy);
        $http3Pump = new AdaptivePumpStrategy(policy: $policy);

        foreach ($samples as $sample) {
            $wireLimit = $http2->wireLimit($sample);
            $writeLimit = $http3Response->writeLimit($sample, 128);
            $http3Pump->observe(
                intdiv($sample->backlogBasisPoints, 100),
                intdiv($sample->activeBasisPoints, 100),
                100,
            );

            expect($wireLimit)->toBeGreaterThanOrEqual(1)
                ->and($wireLimit)->toBeLessThanOrEqual(1_024)
                ->and($writeLimit)->toBeGreaterThanOrEqual(1)
                ->and($writeLimit)->toBeLessThanOrEqual(128)
                ->and($http3Pump->acceptLimit(64))->toBeGreaterThanOrEqual(1)
                ->and($http3Pump->acceptLimit(64))->toBeLessThanOrEqual(64)
                ->and($http3Pump->readLimit(256))->toBeGreaterThanOrEqual(1)
                ->and($http3Pump->readLimit(256))->toBeLessThanOrEqual(256)
                ->and($http3Pump->byteLimit(262_144))->toBeGreaterThanOrEqual(1)
                ->and($http3Pump->byteLimit(262_144))->toBeLessThanOrEqual(262_144);
        }
    }
});

it('keeps hard resource limits independent from adaptive policy', function (): void {
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
    $http3 = new Http3Options(
        limits: new Http3Limits(
            maxConcurrentRequestStreams: 9,
            maxStreamsPerConnection: 99,
        ),
        inboundAdaptive: $throughput,
        outboundAdaptive: $throughput,
    );

    expect($http1->maxBodyBytes)->toBe(12_345)
        ->and($http1->maxPendingBodyBytes)->toBe(8_192)
        ->and($http2->maxConcurrentStreams)->toBe(7)
        ->and($http2->maxStreamsPerConnection)->toBe(77)
        ->and($http3->limits->maxConcurrentRequestStreams)->toBe(9)
        ->and($http3->limits->maxStreamsPerConnection)->toBe(99);
});
