<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Supervisor\Internal;

use Infocyph\Runwire\Supervisor\AutoscaleDecisionEngine;
use Infocyph\Runwire\Supervisor\AutoscalePolicy;
use InvalidArgumentException;

/**
 * Monotonic, bounded observation gate for proposed native worker counts.
 *
 * A live supervisor must independently implement fork ownership, readiness,
 * graceful drain and process-tree fault recovery.
 */
final class AutoscaleWindowController
{
    private ?float $lastChangeAt = null;

    private ?float $lastObservedAt = null;

    private ?float $lastSampleAt = null;

    private int $pendingDirection = 0;

    private int $pendingWindows = 0;

    /** Configure sustained pressure and monotonic sample validation. */
    public function __construct(
        private readonly AutoscalePolicy $policy,
        private readonly AutoscaleDecisionEngine $engine = new AutoscaleDecisionEngine(),
        private readonly int $consecutiveWindows = 2,
    ) {
        if ($consecutiveWindows < 2 || $consecutiveWindows > 16) {
            throw new InvalidArgumentException('Autoscale requires between 2 and 16 stable sample windows.');
        }
    }

    /** Return the last accepted worker-count adjustment timestamp. */
    public function lastChangeAt(): ?float
    {
        return $this->lastChangeAt;
    }

    /** Propose a worker target from monotonic metrics, never spawning children. */
    public function observe(
        int $currentWorkers,
        float $cpuRatio,
        int $queued,
        float $successfulRps,
        float $p99Milliseconds,
        float $observedAtSeconds,
    ): int {
        $this->assertMonotonicSample($observedAtSeconds);

        $elapsed = $this->lastChangeAt === null
            ? $this->policy->cooldownSeconds
            : $observedAtSeconds - $this->lastChangeAt;
        $desired = $this->engine->target(
            $this->policy,
            $currentWorkers,
            $cpuRatio,
            $queued,
            $successfulRps,
            $p99Milliseconds,
            max(0, $elapsed),
        );

        // Operator overrides and worker-count safety bounds take precedence.
        if ($this->policy->fixed()
            || $currentWorkers < $this->policy->minWorkers
            || $currentWorkers > $this->policy->maxWorkers) {
            $this->resetPressure();
            if ($desired !== $currentWorkers) {
                $this->lastChangeAt = $observedAtSeconds;
            }

            return $desired;
        }
        if ($this->lastSampleAt !== null
            && $observedAtSeconds - $this->lastSampleAt < $this->policy->sampleSeconds) {
            return $currentWorkers;
        }
        $this->lastSampleAt = $observedAtSeconds;

        if ($desired === $currentWorkers) {
            $this->resetPressure();

            return $currentWorkers;
        }

        return $this->vote($currentWorkers, $desired, $observedAtSeconds);
    }

    /** Discard consecutive-window pressure when telemetry is missing or invalid. */
    public function invalidateSample(): void
    {
        $this->resetPressure();
    }

    /** Return how many validated samples agree on the next scaling direction. */
    public function pendingWindows(): int
    {
        return $this->pendingWindows;
    }

    private function assertMonotonicSample(float $observedAtSeconds): void
    {
        if (!is_finite($observedAtSeconds) || $observedAtSeconds < 0
            || ($this->lastObservedAt !== null && $observedAtSeconds <= $this->lastObservedAt)) {
            throw new InvalidArgumentException('Autoscale timestamps must be finite and strictly increasing.');
        }
        $this->lastObservedAt = $observedAtSeconds;
    }

    private function resetPressure(): void
    {
        $this->pendingDirection = 0;
        $this->pendingWindows = 0;
    }

    private function vote(int $currentWorkers, int $desired, float $observedAtSeconds): int
    {
        $direction = $desired > $currentWorkers ? 1 : -1;
        if ($direction !== $this->pendingDirection) {
            $this->pendingWindows = 0;
            $this->pendingDirection = $direction;
        }
        ++$this->pendingWindows;
        if ($this->pendingWindows < $this->consecutiveWindows) {
            return $currentWorkers;
        }
        $this->lastChangeAt = $observedAtSeconds;
        $this->resetPressure();

        return $desired;
    }
}
