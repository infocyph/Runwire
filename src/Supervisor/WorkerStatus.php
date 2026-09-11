<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Supervisor;

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
    ) {}
}
