<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Supervisor;

use Infocyph\Runwire\Metrics\RuntimeMetricsSnapshot;
use Infocyph\Runwire\Supervisor\Enum\ShutdownReason;
use Infocyph\Runwire\Supervisor\Enum\WorkerRole;
use Infocyph\Runwire\Supervisor\Enum\WorkerState;

/**
 * Represents a point-in-time status snapshot for one supervised worker process.
 */
final readonly class WorkerStatus
{
    /**
     * Create a worker status snapshot.
     */
    public function __construct(
        public string $group,
        public int $slot,
        public int $pid,
        public int $generation,
        public WorkerState $state,
        public int $restartCount,
        public float $startedAtMonotonic,
        public float $ageSeconds,
        public bool $current,
        public ?int $replacesPid = null,
        public bool $reloadable = true,
        public ?ShutdownReason $shutdownReason = null,
        public float $busySeconds = 0.0,
        public bool $busyBeyondThreshold = false,
        public ?RuntimeMetricsSnapshot $metrics = null,
        public WorkerRole $role = WorkerRole::CUSTOM,
    ) {}
}
