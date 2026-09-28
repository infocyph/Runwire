<?php

declare(strict_types=1);

use Infocyph\Runwire\Http\AdaptiveProtocolPolicy;
use Infocyph\Runwire\Http\Enum\AdaptivePolicyMode;
use Infocyph\Runwire\Http\Http1\Internal\AdaptiveConnectionStrategy;
use Infocyph\Runwire\Http\Http2\Internal\AdaptiveResponseStrategy as Http2AdaptiveResponseStrategy;
use Infocyph\Runwire\Http\Http3\Internal\AdaptivePollStrategy;
use Infocyph\Runwire\Http\Http3\Internal\AdaptivePumpStrategy;
use Infocyph\Runwire\Http\Http3\Internal\AdaptiveResponseStrategy as Http3AdaptiveResponseStrategy;
use Infocyph\Runwire\Http\Internal\AdaptiveLoadSample;
use InvalidArgumentException;

it('supports deterministic fixed adaptive protocol modes', function (): void {
    $sample = new AdaptiveLoadSample(true, 10_000, 10_000);

    expect((new AdaptiveConnectionStrategy(
        policy: new AdaptiveProtocolPolicy(mode: AdaptivePolicyMode::LATENCY),
    ))->tcpNoDelay($sample))->toBeTrue()
        ->and((new AdaptiveConnectionStrategy(
            policy: new AdaptiveProtocolPolicy(mode: AdaptivePolicyMode::THROUGHPUT),
        ))->tcpNoDelay($sample))->toBeFalse()
        ->and((new AdaptiveConnectionStrategy(
            policy: new AdaptiveProtocolPolicy(mode: AdaptivePolicyMode::FIXED),
        ))->tcpNoDelay($sample))->toBeTrue();

    expect((new Http2AdaptiveResponseStrategy(
        policy: new AdaptiveProtocolPolicy(mode: AdaptivePolicyMode::FIXED),
    ))->wireLimit($sample))->toBe(1_024)
        ->and((new Http2AdaptiveResponseStrategy(
            policy: new AdaptiveProtocolPolicy(mode: AdaptivePolicyMode::THROUGHPUT),
        ))->wireLimit($sample))->toBe(256);

    expect((new Http3AdaptiveResponseStrategy(
        policy: new AdaptiveProtocolPolicy(mode: AdaptivePolicyMode::FIXED),
    ))->writeLimit($sample, 128))->toBe(128)
        ->and((new Http3AdaptiveResponseStrategy(
            policy: new AdaptiveProtocolPolicy(mode: AdaptivePolicyMode::LATENCY),
        ))->writeLimit($sample, 128))->toBe(32);
});

it('supports protocol-local AUTO crossover overrides', function (): void {
    $policy = new AdaptiveProtocolPolicy(
        lowWatermarkBasisPoints: 100,
        highWatermarkBasisPoints: 200,
        transitionSamples: 1,
        ewmaNumerator: 1,
        ewmaDenominator: 1,
    );
    $strategy = new Http2AdaptiveResponseStrategy(policy: $policy);

    expect($strategy->wireLimit(new AdaptiveLoadSample(false, 10_000, 10_000)))->toBe(256);
});

it('keeps HTTP3 fixed policy deterministic and poll timing static', function (): void {
    $fixed = new AdaptiveProtocolPolicy(mode: AdaptivePolicyMode::FIXED);
    $pump = new AdaptivePumpStrategy(policy: $fixed);

    $pump->observe(100, 100, 100);

    expect($pump->acceptLimit(64))->toBe(64)
        ->and($pump->readLimit(256))->toBe(256)
        ->and(AdaptivePollStrategy::timeout(0.05, true, 0, 1, $fixed))->toBe(0.05);
});

it('rejects invalid adaptive protocol crossover configuration', function (): void {
    expect(fn() => new AdaptiveProtocolPolicy(
        lowWatermarkBasisPoints: 5_000,
        highWatermarkBasisPoints: 5_000,
    ))->toThrow(InvalidArgumentException::class)
        ->and(fn() => new AdaptiveProtocolPolicy(transitionSamples: 0))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn() => new AdaptiveProtocolPolicy(ewmaNumerator: 3, ewmaDenominator: 2))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn() => new AdaptiveProtocolPolicy(
            ewmaNumerator: PHP_INT_MAX,
            ewmaDenominator: PHP_INT_MAX,
        ))->toThrow(InvalidArgumentException::class);
});


it('defaults H1 H2 and H3 to FIXED', function (): void {
    $http1 = new \Infocyph\Runwire\Http\Http1\Http1Limits();
    $http2 = new \Infocyph\Runwire\Http\Http2\Http2Limits();
    $http3 = new \Infocyph\Runwire\Http\Http3\Http3Options();

    expect($http1->adaptive->mode)->toBe(AdaptivePolicyMode::FIXED)
        ->and($http2->adaptive->mode)->toBe(AdaptivePolicyMode::FIXED)
        ->and($http3->inboundAdaptive->mode)->toBe(AdaptivePolicyMode::FIXED)
        ->and($http3->outboundAdaptive->mode)->toBe(AdaptivePolicyMode::FIXED);
});
