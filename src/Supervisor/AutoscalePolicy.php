<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Supervisor;

use InvalidArgumentException;

/**
 * Native-only autoscaling thresholds; this value object never scales workers.
 */
final readonly class AutoscalePolicy
{
    /**
     * Define bounded CPU, queue, p99, throughput and operator controls.
     */
    public function __construct(
        public int $minWorkers = 1,
        public int $maxWorkers = 8,
        public float $scaleInCpuRatio = 0.30,
        public float $scaleOutCpuRatio = 0.75,
        public int $scaleOutQueueDepth = 16,
        public float $scaleOutP99Milliseconds = 250.0,
        public float $scaleInSuccessfulRps = 1.0,
        public float $sampleSeconds = 10.0,
        public float $cooldownSeconds = 30.0,
        public int $maxStepPerWindow = 1,
        public ?int $workerOverride = null,
    ) {
        if ($minWorkers < 1 || $maxWorkers < $minWorkers || $maxWorkers > 1_024) {
            throw new InvalidArgumentException('Autoscale workers must be bounded from 1 to 1024.');
        }
        if (!is_finite($scaleInCpuRatio) || !is_finite($scaleOutCpuRatio)
            || $scaleInCpuRatio <= 0 || $scaleOutCpuRatio >= 1
            || $scaleInCpuRatio >= $scaleOutCpuRatio) {
            throw new InvalidArgumentException('CPU hysteresis requires 0 < scale-in < scale-out < 1.');
        }
        if ($scaleOutQueueDepth < 1 || $scaleOutQueueDepth > 1_000_000
            || !is_finite($scaleOutP99Milliseconds) || $scaleOutP99Milliseconds <= 0
            || !is_finite($scaleInSuccessfulRps) || $scaleInSuccessfulRps < 0) {
            throw new InvalidArgumentException('Autoscale queue, p99 or successful-RPS threshold is invalid.');
        }
        if (!is_finite($sampleSeconds) || $sampleSeconds < 1
            || !is_finite($cooldownSeconds) || $cooldownSeconds < $sampleSeconds
            || $maxStepPerWindow < 1 || $maxStepPerWindow > $maxWorkers) {
            throw new InvalidArgumentException('Autoscale sample, cooldown or worker step is invalid.');
        }
        if ($workerOverride !== null && ($workerOverride < $minWorkers || $workerOverride > $maxWorkers)) {
            throw new InvalidArgumentException('Operator fixed worker count is outside configured bounds.');
        }
    }

    /** Return the effective maximum, honoring an explicit fixed operator setting. */
    public function effectiveMaxWorkers(): int
    {
        return $this->workerOverride ?? $this->maxWorkers;
    }

    /** Return the effective minimum, honoring an explicit fixed operator setting. */
    public function effectiveMinWorkers(): int
    {
        return $this->workerOverride ?? $this->minWorkers;
    }

    /** Report whether operator policy disables automatic worker count changes. */
    public function fixed(): bool
    {
        return $this->workerOverride !== null;
    }
}
