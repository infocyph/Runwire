<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Http3\Internal;

use Infocyph\Runwire\Http\AdaptiveProtocolPolicy;
use Infocyph\Runwire\Http\Enum\AdaptiveLoadState;
use Infocyph\Runwire\Http\Enum\AdaptivePolicyMode;
use Infocyph\Runwire\Http\Internal\AdaptiveLoadController;
use Infocyph\Runwire\Http\Internal\AdaptiveLoadSample;
use InvalidArgumentException;

/**
 * Selects HTTP/3 accept and inbound pump effort from ready/active stream load.
 *
 * @internal
 */
final readonly class AdaptivePumpStrategy
{
    private AdaptiveLoadController $controller;

    /**
     * Create the HTTP/3 inbound adaptive strategy.
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
     * Return the stream-accept budget for the current state.
     */
    public function acceptLimit(int $maximum): int
    {
        self::assertMaximum($maximum);

        if ($this->policy->mode === AdaptivePolicyMode::FIXED) {
            return $maximum;
        }

        return match ($this->state()) {
            AdaptiveLoadState::BALANCED => self::scaled($maximum, 1, 2),
            AdaptiveLoadState::LATENCY => $maximum,
            AdaptiveLoadState::THROUGHPUT => self::scaled($maximum, 1, 4),
        };
    }

    /**
     * Return the inbound-byte budget for the current state.
     */
    public function byteLimit(int $maximum): int
    {
        return $this->readLimit($maximum);
    }

    /**
     * Observe ready and active request-stream pressure.
     */
    public function observe(int $readyWork, int $activeWork, int $capacity): AdaptiveLoadState
    {
        if ($readyWork < 0 || $activeWork < 0 || $capacity < 1) {
            throw new InvalidArgumentException('HTTP/3 adaptive pump counters must be non-negative with positive capacity.');
        }

        if ($this->policy->mode !== AdaptivePolicyMode::AUTO) {
            return $this->state();
        }

        return $this->controller->observe(AdaptiveLoadSample::fromCounters(
            pressured: false,
            queuedBytes: $readyWork,
            queueCapacityBytes: $capacity,
            activeWork: $activeWork,
            activeCapacity: $capacity,
        ));
    }

    /**
     * Return the read-operation budget for the current state.
     */
    public function readLimit(int $maximum): int
    {
        self::assertMaximum($maximum);

        if ($this->policy->mode === AdaptivePolicyMode::FIXED) {
            return $maximum;
        }

        return match ($this->state()) {
            AdaptiveLoadState::BALANCED => self::scaled($maximum, 1, 2),
            AdaptiveLoadState::LATENCY => self::scaled($maximum, 1, 4),
            AdaptiveLoadState::THROUGHPUT => $maximum,
        };
    }

    /**
     * Return the current inbound adaptive state.
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

    private static function assertMaximum(int $maximum): void
    {
        if ($maximum < 1) {
            throw new InvalidArgumentException('HTTP/3 adaptive pump maximum must be positive.');
        }
    }

    private static function scaled(int $maximum, int $numerator, int $denominator): int
    {
        return max(1, intdiv(($maximum * $numerator) + $denominator - 1, $denominator));
    }
}
