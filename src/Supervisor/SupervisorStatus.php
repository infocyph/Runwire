<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Supervisor;

final readonly class SupervisorStatus
{
    /**
     * @param list<WorkerStatus> $workers
     */
    public function __construct(
        public bool $running,
        public bool $stopping,
        public bool $reloading,
        public int $generation,
        public array $workers,
    ) {
    }
}
