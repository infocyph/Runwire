<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Coroutine;

final readonly class CoroutineDiagnosticsSnapshot
{
    public function __construct(
        public int $sampledAtMonotonicNanoseconds,
        public int $activeTasks = 0,
        public int $runnableTasks = 0,
        public int $suspendedTasks = 0,
        public int $completedTotal = 0,
        public int $failedTotal = 0,
        public int $cancelledTotal = 0,
        public int $spawnedTotal = 0,
        public int $readyQueueDepth = 0,
        public int $readyQueueMaxDepth = 0,
        public int $resumesTotal = 0,
        public int $rootScopesActive = 0,
        public int $requestScopesActive = 0,
        public int $backgroundScopesActive = 0,
        public int $backgroundTasksActive = 0,
        public int $loopTimersActive = 0,
        public int $loopDeferredBacklog = 0,
        public int $loopReadWatchers = 0,
        public int $loopWriteWatchers = 0,
        public int $maxTasks = 0,
        public int $maxReadyBacklog = 0,
        public int $maxWaitersPerPrimitive = 0,
        public int $maxResumesPerTick = 0,
    ) {}
}
