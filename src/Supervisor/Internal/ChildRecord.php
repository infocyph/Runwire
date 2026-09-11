<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Supervisor\Internal;

use Infocyph\Runwire\Supervisor\WorkerGroup;
use Infocyph\Runwire\Supervisor\WorkerState;

final class ChildRecord
{
    /** @var resource|null */
    public mixed $readyStream;

    /** @param resource $readyStream */
    public function __construct(
        public WorkerGroup $group,
        public int $slot,
        public int $pid,
        public int $generation,
        public int $restartCount,
        public int $startedAtNs,
        mixed $readyStream,
        public int $readyWatcherId,
        public int $readyTimerId,
        public ?int $replacesPid = null,
        public bool $recycleReplacement = false,
        public WorkerState $state = WorkerState::STARTING,
        public bool $expectedStop = false,
        public ?int $killTimerId = null,
    ) {
        $this->readyStream = $readyStream;
    }
}
