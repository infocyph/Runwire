<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Supervisor\Internal;

use InvalidArgumentException;

/**
 * @internal A bounded, monotonic, cooldown-aware worker capacity decision.
 *
 * This is only the decision primitive: fork/stop ownership remains the
 * supervisor's responsibility. Never retire BUSY/STARTING workers directly.
 */
final class AutoscaleHysteresis
{
    private int $highSamples = 0;

    private ?int $lastChangeNanoseconds = null;

    private int $lowSamples = 0;

    /** Define stable capacity thresholds without scheduling any process work. */
    public function __construct(
        private readonly int $minWorkers,
        private readonly int $maxWorkers,
        private readonly float $scaleOutRatio = 0.85,
        private readonly float $scaleInRatio = 0.20,
        private readonly int $scaleOutSamples = 3,
        private readonly int $scaleInSamples = 8,
        private readonly float $cooldownSeconds = 10.0,
    ) {
        if ($minWorkers < 1 || $maxWorkers < $minWorkers || $maxWorkers > 1_024
            || !is_finite($scaleOutRatio) || !is_finite($scaleInRatio)
            || $scaleOutRatio <= 0 || $scaleOutRatio > 1
            || $scaleInRatio < 0 || $scaleInRatio >= $scaleOutRatio
            || $scaleOutSamples < 1 || $scaleInSamples < 1
            || !is_finite($cooldownSeconds) || $cooldownSeconds < 0) {
            throw new InvalidArgumentException('Invalid bounded worker autoscale hysteresis configuration.');
        }
    }

    /**
     * Return an at-most-one-worker capacity change from monotonic samples.
     * Request backlog prevents idle-down, and a full worker group may scale
     * out even before the application records a queued request.
     */
    public function propose(int $current, int $busy, int $queued, int $nowNanoseconds): int
    {
        $this->assertSample($current, $busy, $queued, $nowNanoseconds);

        $pressure = $current === 0 ? 0.0 : $busy / $current;
        $high = $queued > 0 || $pressure >= $this->scaleOutRatio;
        $low = $queued === 0 && $pressure <= $this->scaleInRatio;
        $this->highSamples = $high ? min($this->highSamples + 1, $this->scaleOutSamples) : 0;
        $this->lowSamples = $low ? min($this->lowSamples + 1, $this->scaleInSamples) : 0;

        if ($this->lastChangeNanoseconds !== null
            && ($nowNanoseconds - $this->lastChangeNanoseconds) < $this->cooldownSeconds * 1_000_000_000) {
            return $current;
        }
        if ($this->highSamples >= $this->scaleOutSamples && $current < $this->maxWorkers) {
            return $this->change($current + 1, $nowNanoseconds);
        }
        if ($this->lowSamples >= $this->scaleInSamples && $current > $this->minWorkers) {
            return $this->change($current - 1, $nowNanoseconds);
        }

        return $current;
    }

    /** Discard stale observations across reload, recycle or supervisor stop. */
    public function reset(): void
    {
        $this->highSamples = 0;
        $this->lowSamples = 0;
        $this->lastChangeNanoseconds = null;
    }

    private function assertSample(int $current, int $busy, int $queued, int $nowNanoseconds): void
    {
        if ($current < $this->minWorkers || $current > $this->maxWorkers
            || $busy < 0 || $busy > $current || $queued < 0 || $nowNanoseconds < 0) {
            throw new InvalidArgumentException('Invalid worker admission sample.');
        }
    }

    private function change(int $target, int $time): int
    {
        $this->highSamples = 0;
        $this->lowSamples = 0;
        $this->lastChangeNanoseconds = $time;

        return $target;
    }
}
