<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Supervisor;

final readonly class SupervisorStatus
{
    /** @param list<WorkerStatus> $workers */
    public function __construct(
        public string $runtimeId,
        public int $masterPid,
        public ?float $startedAtUnix,
        public float $uptimeSeconds,
        public bool $running,
        public bool $stopping,
        public bool $reloading,
        public bool $reloadQueued,
        public int $generation,
        public int $workerCount,
        public int $currentWorkerCount,
        public int $readyWorkerCount,
        public int $pendingRestartCount,
        public int $lifecycleListenerFailures,
        public array $workers,
    ) {}
}
