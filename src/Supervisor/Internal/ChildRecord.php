<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Supervisor\Internal;

use Infocyph\Runwire\Metrics\RuntimeMetricsSnapshot;
use Infocyph\Runwire\Supervisor\Enum\ShutdownReason;
use Infocyph\Runwire\Supervisor\Enum\WorkerExitReason;
use Infocyph\Runwire\Supervisor\Enum\WorkerState;
use Infocyph\Runwire\Supervisor\WorkerGroup;

final class ChildRecord
{
    /** @param resource|null $readyStream */
    public function __construct(
        public WorkerGroup $group,
        public int $slot,
        public int $pid,
        public int $generation,
        public int $restartCount,
        public int $startedAtNs,
        public mixed $readyStream,
        public int $readyWatcherId,
        public int $readyTimerId,
        public ?int $replacesPid = null,
        public bool $recycleReplacement = false,
        public WorkerState $state = WorkerState::STARTING,
        public bool $expectedStop = false,
        public ?int $killTimerId = null,
        public string $lifecycleBuffer = '',
        public ?ShutdownReason $shutdownReason = null,
        public ?WorkerExitReason $exitReason = null,
        public ?int $busySinceNs = null,
        public ?RuntimeMetricsSnapshot $metrics = null,
    ) {}
}
