<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Http2\Internal;

use Infocyph\Runwire\Http\AdaptiveProtocolPolicy;
use Infocyph\Runwire\Http\Enum\AdaptiveLoadState;
use Infocyph\Runwire\Http\Enum\AdaptivePolicyMode;
use Infocyph\Runwire\Http\Internal\AdaptiveLoadController;
use Infocyph\Runwire\Http\Internal\AdaptiveLoadSample;
use InvalidArgumentException;

/**
 * Selects the bounded HTTP/2 initial-response wire budget from current load.
 *
 * @internal
 */
final readonly class AdaptiveResponseStrategy
{
    private AdaptiveLoadController $controller;

    /**
     * Create the HTTP/2 response strategy and validate its state-specific budgets.
     */
    public function __construct(
        ?AdaptiveLoadController $controller = null,
        private AdaptiveProtocolPolicy $policy = new AdaptiveProtocolPolicy(
            lowWatermarkBasisPoints: 1_000,
            highWatermarkBasisPoints: 3_000,
        ),
        private int $latencyWireBytes = 1_024,
        private int $balancedWireBytes = 512,
        private int $throughputWireBytes = 256,
    ) {
        if ($throughputWireBytes < 1
            || $throughputWireBytes > $balancedWireBytes
            || $balancedWireBytes > $latencyWireBytes) {
            throw new InvalidArgumentException(
                'HTTP/2 adaptive wire budgets must satisfy 0 < throughput <= balanced <= latency.',
            );
        }

        $this->controller = $controller ?? new AdaptiveLoadController(
            lowWatermarkBasisPoints: $policy->lowWatermarkBasisPoints,
            highWatermarkBasisPoints: $policy->highWatermarkBasisPoints,
            transitionSamples: $policy->transitionSamples,
            ewmaNumerator: $policy->ewmaNumerator,
            ewmaDenominator: $policy->ewmaDenominator,
        );
    }

    /**
     * Return the current adaptive load state.
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
     * Observe load and return the maximum one-shot wire intent for the resulting state.
     */
    public function wireLimit(AdaptiveLoadSample $sample): int
    {
        if ($this->policy->mode === AdaptivePolicyMode::FIXED) {
            return $this->latencyWireBytes;
        }

        $state = $this->policy->mode === AdaptivePolicyMode::AUTO
            ? $this->controller->observe($sample)
            : $this->state();

        return match ($state) {
            AdaptiveLoadState::BALANCED => $this->balancedWireBytes,
            AdaptiveLoadState::LATENCY => $this->latencyWireBytes,
            AdaptiveLoadState::THROUGHPUT => $this->throughputWireBytes,
        };
    }
}
