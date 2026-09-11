<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Supervisor\Internal;

use Infocyph\Runwire\Supervisor\WorkerGroup;
use Infocyph\Runwire\Supervisor\WorkerState;

final class ChildRecord
{
    /**
     * @param resource $readyStream
     */
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
        public WorkerState $state = WorkerState::STARTING,
        public bool $expectedStop = false,
        public ?int $killTimerId = null,
    ) {
    }
}
