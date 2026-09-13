<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Supervisor;

use Infocyph\Runwire\Supervisor\Enum\ShutdownReason;
use Infocyph\Runwire\Supervisor\Enum\SupervisorEventType;
use Infocyph\Runwire\Supervisor\Enum\WorkerExitReason;
use Infocyph\Runwire\Supervisor\Enum\WorkerState;

final readonly class SupervisorEvent
{
    public function __construct(
        public SupervisorEventType $type,
        public float $atMonotonic,
        public ?string $group = null,
        public ?int $slot = null,
        public ?int $pid = null,
        public ?int $generation = null,
        public ?WorkerState $state = null,
        public ?int $restartCount = null,
        public ?int $exitCode = null,
        public ?int $termSignal = null,
        public ?bool $expected = null,
        public ?float $restartDelaySeconds = null,
        public ?int $replacesPid = null,
        public ?ShutdownReason $shutdownReason = null,
        public ?WorkerExitReason $exitReason = null,
    ) {}
}
