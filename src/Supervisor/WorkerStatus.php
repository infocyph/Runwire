<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Supervisor;

use Infocyph\Runwire\Supervisor\Enum\ShutdownReason;
use Infocyph\Runwire\Supervisor\Enum\WorkerState;

final readonly class WorkerStatus
{
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
    ) {}
}
