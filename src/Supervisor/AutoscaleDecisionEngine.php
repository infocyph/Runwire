<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Supervisor;

use InvalidArgumentException;

/**
 * Pure native autoscale target calculation; never spawns or retires workers.
 */
final readonly class AutoscaleDecisionEngine
{
    /**
     * Compute a bounded target from one validated observation window.
     *
     * @return int Desired workers, not a command to the live supervisor.
     */
    public function target(
        AutoscalePolicy $policy,
        int $currentWorkers,
        float $cpuRatio,
        int $queued,
        float $successfulRps,
        float $p99Milliseconds,
        float $secondsSinceLastChange,
    ): int {
        if ($currentWorkers < 1 || $currentWorkers > 1_024
            || !is_finite($cpuRatio) || $cpuRatio < 0 || $cpuRatio > 1
            || $queued < 0 || !is_finite($successfulRps) || $successfulRps < 0
            || !is_finite($p99Milliseconds) || $p99Milliseconds < 0
            || !is_finite($secondsSinceLastChange) || $secondsSinceLastChange < 0) {
            throw new InvalidArgumentException('Invalid autoscale observation or worker count.');
        }

        if ($policy->fixed()) {
            return $policy->effectiveMinWorkers();
        }
        if ($currentWorkers < $policy->minWorkers) {
            return min($policy->minWorkers, $currentWorkers + $policy->maxStepPerWindow);
        }
        if ($currentWorkers > $policy->maxWorkers) {
            return max($policy->maxWorkers, $currentWorkers - $policy->maxStepPerWindow);
        }
        if ($secondsSinceLastChange < $policy->cooldownSeconds) {
            return $currentWorkers;
        }

        $overloaded = $queued >= $policy->scaleOutQueueDepth
            || ($successfulRps > 0 && ($cpuRatio >= $policy->scaleOutCpuRatio
                || $p99Milliseconds >= $policy->scaleOutP99Milliseconds));
        if ($overloaded) {
            return min($policy->maxWorkers, $currentWorkers + $policy->maxStepPerWindow);
        }
        if ($queued === 0 && $cpuRatio <= $policy->scaleInCpuRatio
            && $successfulRps <= $policy->scaleInSuccessfulRps) {
            return max($policy->minWorkers, $currentWorkers - $policy->maxStepPerWindow);
        }

        return $currentWorkers;
    }
}
