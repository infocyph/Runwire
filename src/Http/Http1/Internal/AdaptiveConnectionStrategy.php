<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Http1\Internal;

use Infocyph\Runwire\Http\AdaptiveProtocolPolicy;
use Infocyph\Runwire\Http\Enum\AdaptiveLoadState;
use Infocyph\Runwire\Http\Enum\AdaptivePolicyMode;
use Infocyph\Runwire\Http\Internal\AdaptiveLoadController;
use Infocyph\Runwire\Http\Internal\AdaptiveLoadSample;

/**
 * Selects the default TCP_NODELAY policy for newly attached HTTP/1.1 connections.
 *
 * @internal
 */
final readonly class AdaptiveConnectionStrategy
{
    private AdaptiveLoadController $controller;

    /**
     * Create the worker-scoped HTTP/1.1 connection strategy.
     */
    public function __construct(
        ?AdaptiveLoadController $controller = null,
        private AdaptiveProtocolPolicy $policy = new AdaptiveProtocolPolicy(),
    ) {
        $this->controller = $controller ?? new AdaptiveLoadController(
            lowWatermarkBasisPoints: $policy->lowWatermarkBasisPoints,
            highWatermarkBasisPoints: $policy->highWatermarkBasisPoints,
            transitionSamples: $policy->transitionSamples,
            ewmaNumerator: $policy->ewmaNumerator,
            ewmaDenominator: $policy->ewmaDenominator,
        );
    }

    /**
     * Return the current worker load state.
     */
    public function state(): AdaptiveLoadState
    {
        return match ($this->policy->mode) {
            AdaptivePolicyMode::AUTO => $this->controller->state(),
            AdaptivePolicyMode::FIXED => AdaptiveLoadState::BALANCED,
            AdaptivePolicyMode::LATENCY => AdaptiveLoadState::LATENCY,
            AdaptivePolicyMode::THROUGHPUT => AdaptiveLoadState::THROUGHPUT,
        };
    }

    /**
     * Observe worker load and choose the NODELAY default for the next HTTP/1.1 connection.
     */
    public function tcpNoDelay(AdaptiveLoadSample $sample): bool
    {
        return match ($this->policy->mode) {
            AdaptivePolicyMode::AUTO => $this->controller->observe($sample) !== AdaptiveLoadState::THROUGHPUT,
            AdaptivePolicyMode::FIXED, AdaptivePolicyMode::LATENCY => true,
            AdaptivePolicyMode::THROUGHPUT => false,
        };
    }
}
