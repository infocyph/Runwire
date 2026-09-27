<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Internal;

use Infocyph\Runwire\Http\Enum\AdaptiveLoadState;
use InvalidArgumentException;

/**
 * Maintains a smoothed, hysteretic protocol scheduling state.
 *
 * @internal
 */
final class AdaptiveLoadController
{
    private int $candidateSamples = 0;

    private AdaptiveLoadState $candidateState = AdaptiveLoadState::BALANCED;

    private ?int $smoothedScore = null;

    private AdaptiveLoadState $state = AdaptiveLoadState::BALANCED;

    /**
     * Create an adaptive load controller with bounded hysteresis and EWMA smoothing.
     */
    public function __construct(
        private readonly int $lowWatermarkBasisPoints = 2_500,
        private readonly int $highWatermarkBasisPoints = 6_500,
        private readonly int $transitionSamples = 3,
        private readonly int $ewmaNumerator = 1,
        private readonly int $ewmaDenominator = 2,
    ) {
        if ($lowWatermarkBasisPoints < 0
            || $highWatermarkBasisPoints > AdaptiveLoadSample::MAX_BASIS_POINTS
            || $lowWatermarkBasisPoints >= $highWatermarkBasisPoints) {
            throw new InvalidArgumentException('Adaptive load watermarks must satisfy 0 <= low < high <= 10000.');
        }
        if ($transitionSamples < 1) {
            throw new InvalidArgumentException('Adaptive transition sample count must be positive.');
        }
        if ($ewmaNumerator < 1 || $ewmaDenominator < 1 || $ewmaNumerator > $ewmaDenominator) {
            throw new InvalidArgumentException('Adaptive EWMA ratio must satisfy 1 <= numerator <= denominator.');
        }
    }

    /**
     * Observe one normalized load sample and return the current scheduling state.
     */
    public function observe(AdaptiveLoadSample $sample): AdaptiveLoadState
    {
        $this->updateSmoothedScore($sample->score());
        $target = $this->targetState($sample);

        if ($target === $this->state) {
            $this->candidateState = $this->state;
            $this->candidateSamples = 0;

            return $this->state;
        }

        if ($target !== $this->candidateState) {
            $this->candidateState = $target;
            $this->candidateSamples = 1;
        } else {
            ++$this->candidateSamples;
        }

        if ($this->candidateSamples >= $this->transitionSamples) {
            $this->state = $target;
            $this->candidateState = $target;
            $this->candidateSamples = 0;
        }

        return $this->state;
    }

    /**
     * Reset the controller to the balanced state with no retained score.
     */
    public function reset(): void
    {
        $this->candidateState = AdaptiveLoadState::BALANCED;
        $this->candidateSamples = 0;
        $this->smoothedScore = null;
        $this->state = AdaptiveLoadState::BALANCED;
    }

    /**
     * Return the current EWMA load score in basis points.
     */
    public function smoothedScore(): int
    {
        return $this->smoothedScore ?? 0;
    }

    /**
     * Return the current adaptive scheduling state.
     */
    public function state(): AdaptiveLoadState
    {
        return $this->state;
    }

    private function targetState(AdaptiveLoadSample $sample): AdaptiveLoadState
    {
        if ($sample->pressured) {
            return AdaptiveLoadState::THROUGHPUT;
        }

        $score = $this->smoothedScore();
        if ($score >= $this->highWatermarkBasisPoints) {
            return AdaptiveLoadState::THROUGHPUT;
        }
        if ($score <= $this->lowWatermarkBasisPoints) {
            return AdaptiveLoadState::LATENCY;
        }

        return AdaptiveLoadState::BALANCED;
    }

    private function updateSmoothedScore(int $score): void
    {
        if ($this->smoothedScore === null) {
            $this->smoothedScore = $score;

            return;
        }

        $retained = $this->ewmaDenominator - $this->ewmaNumerator;
        $this->smoothedScore = intdiv(
            ($this->smoothedScore * $retained) + ($score * $this->ewmaNumerator),
            $this->ewmaDenominator,
        );
    }
}
