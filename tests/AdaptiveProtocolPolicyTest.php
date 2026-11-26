<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Tests;

use Infocyph\Runwire\Http\AdaptiveProtocolPolicy;
use Infocyph\Runwire\Http\Enum\AdaptivePolicyMode;
use Infocyph\Runwire\Http\Http1\Internal\AdaptiveConnectionStrategy;
use Infocyph\Runwire\Http\Http2\Internal\AdaptiveResponseStrategy as Http2AdaptiveResponseStrategy;
use Infocyph\Runwire\Http\Internal\AdaptiveLoadSample;
use InvalidArgumentException;

it('supports deterministic fixed adaptive protocol modes', function (): void {
    runwireOwnedAdaptiveProtocolPolicyTestCase01();
});

/**
 * @throws \Throwable
 */
function runwireOwnedAdaptiveProtocolPolicyTestCase01(): void
{
    runwireAdaptiveProtocolPolicyCase01();
}

it('supports protocol-local AUTO crossover overrides', function (): void {
    runwireOwnedAdaptiveProtocolPolicyTestCase02();
});

/**
 * @throws \Throwable
 */
function runwireOwnedAdaptiveProtocolPolicyTestCase02(): void
{
    runwireAdaptiveProtocolPolicyCase02();
}

it('rejects invalid adaptive protocol crossover configuration', function (): void {
    runwireOwnedAdaptiveProtocolPolicyTestCase04();
});

/**
 * @throws \Throwable
 */
function runwireOwnedAdaptiveProtocolPolicyTestCase04(): void
{
    runwireAdaptiveProtocolPolicyCase04();
}


it('defaults H1 and H2 to FIXED', function (): void {
    runwireOwnedAdaptiveProtocolPolicyTestCase05();
});

/**
 * @throws \Throwable
 */
function runwireOwnedAdaptiveProtocolPolicyTestCase05(): void
{
    runwireAdaptiveProtocolPolicyCase05();
}

/**
 * @throws \Throwable
 */
function runwireAdaptiveProtocolPolicyCase01(): void
{
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

}

/**
 * @throws \Throwable
 */
function runwireAdaptiveProtocolPolicyCase02(): void
{
    $policy = new AdaptiveProtocolPolicy(
        lowWatermarkBasisPoints: 100,
        highWatermarkBasisPoints: 200,
        transitionSamples: 1,
        ewmaNumerator: 1,
        ewmaDenominator: 1,
    );
    $strategy = new Http2AdaptiveResponseStrategy(policy: $policy);

    expect($strategy->wireLimit(new AdaptiveLoadSample(false, 10_000, 10_000)))->toBe(256);
}

/**
 * @throws \Throwable
 */
function runwireAdaptiveProtocolPolicyCase04(): void
{
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
}

/**
 * @throws \Throwable
 */
function runwireAdaptiveProtocolPolicyCase05(): void
{
    $http1 = new \Infocyph\Runwire\Http\Http1\Http1Limits();
    $http2 = new \Infocyph\Runwire\Http\Http2\Http2Limits();

    expect($http1->adaptive->mode)->toBe(AdaptivePolicyMode::FIXED)
        ->and($http2->adaptive->mode)->toBe(AdaptivePolicyMode::FIXED);
}

