<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Supervisor\Internal;

use Infocyph\Runwire\Metrics\RuntimeHealthSnapshot;
use Infocyph\Runwire\Supervisor\Enum\WorkerState;
use Infocyph\Runwire\Supervisor\SupervisorStatus;
use Infocyph\Runwire\Supervisor\WorkerStatus;

final class SupervisorStatusBuilder
{
    private const int NANOS_PER_SECOND = 1_000_000_000;

    /**
     * @param array<int, ChildRecord> $children
     * @param array<string, array<int, int>> $currentSlots
     * @param array<string, int> $exitReasonCounts
     * @param array<string, int> $lifecycleListenerFailureCounts
     * @param array<string, int> $restartReasonCounts
     */
    public static function build(
        string $runtimeId,
        int $masterPid,
        ?int $startedAtNs,
        ?float $startedAtUnix,
        bool $running,
        bool $stopping,
        bool $reloading,
        bool $reloadQueued,
        int $generation,
        array $children,
        array $currentSlots,
        int $pendingRestartCount,
        int $lifecycleListenerFailures,
        bool $generationReady = false,
        bool $reloadFailed = false,
        array $exitReasonCounts = [],
        array $restartReasonCounts = [],
        array $lifecycleListenerFailureCounts = [],
        float $busyWorkerThresholdSeconds = 30.0,
    ): SupervisorStatus {
        $nowNs = self::nowNanoseconds();
        $now = $nowNs / self::NANOS_PER_SECOND;
        $workers = [];
        $snapshots = [];
        $ready = 0;
        $current = 0;
        $currentServing = 0;
        $unhealthy = false;
        $draining = $stopping;

        foreach ($children as $record) {
            $isCurrent = ($currentSlots[$record->group->name][$record->slot] ?? null) === $record->pid;
            $serving = $record->state->serving();
            $current += $isCurrent ? 1 : 0;
            $ready += $serving ? 1 : 0;
            $currentServing += $isCurrent && $serving ? 1 : 0;
            $unhealthy = $unhealthy || ($isCurrent && self::unhealthyState($record->state));
            $draining = $draining || in_array($record->state, [WorkerState::DRAINING, WorkerState::STOPPING], true);
            $started = $record->startedAtNs / self::NANOS_PER_SECOND;
            $busySeconds = self::busySeconds($record, $nowNs);
            if ($record->metrics !== null) {
                $snapshots[] = $record->metrics;
            }
            $workers[] = new WorkerStatus(
                group: $record->group->name,
                slot: $record->slot,
                pid: $record->pid,
                generation: $record->generation,
                state: $record->state,
                restartCount: $record->restartCount,
                startedAtMonotonic: $started,
                ageSeconds: max(0.0, $now - $started),
                current: $isCurrent,
                replacesPid: $record->replacesPid,
                reloadable: $record->group->reloadable,
                shutdownReason: $record->shutdownReason,
                busySeconds: $busySeconds,
                busyBeyondThreshold: $busySeconds >= $busyWorkerThresholdSeconds,
                metrics: $record->metrics,
            );
        }

        self::sortWorkers($workers);
        $started = $startedAtNs === null ? null : $startedAtNs / self::NANOS_PER_SECOND;
        $health = new RuntimeHealthSnapshot(
            live: $running,
            ready: $running
                && !$stopping
                && $generationReady
                && $pendingRestartCount === 0
                && $current > 0
                && $currentServing === $current,
            healthy: $running && !$unhealthy && !$reloadFailed && $pendingRestartCount === 0,
            draining: $draining,
        );

        return new SupervisorStatus(
            runtimeId: $runtimeId,
            masterPid: $masterPid,
            startedAtUnix: $startedAtUnix,
            uptimeSeconds: $started === null ? 0.0 : max(0.0, $now - $started),
            running: $running,
            stopping: $stopping,
            reloading: $reloading,
            reloadQueued: $reloadQueued,
            generation: $generation,
            workerCount: count($workers),
            currentWorkerCount: $current,
            readyWorkerCount: $ready,
            pendingRestartCount: $pendingRestartCount,
            lifecycleListenerFailures: $lifecycleListenerFailures,
            workers: $workers,
            health: $health,
            metrics: RuntimeMetricsAggregator::aggregate($snapshots),
            generationReady: $generationReady,
            reloadFailed: $reloadFailed,
            exitReasonCounts: $exitReasonCounts,
            restartReasonCounts: $restartReasonCounts,
            lifecycleListenerFailureCounts: $lifecycleListenerFailureCounts,
        );
    }

    private static function busySeconds(ChildRecord $record, int $nowNs): float
    {
        if ($record->busySinceNs !== null) {
            return max(0.0, ($nowNs - $record->busySinceNs) / self::NANOS_PER_SECOND);
        }

        return $record->metrics?->workerBusySeconds ?? 0.0;
    }

    private static function nowNanoseconds(): int
    {
        $now = hrtime(true);

        return is_int($now) ? $now : (int) $now;
    }

    /** @param list<WorkerStatus> $workers */
    private static function sortWorkers(array &$workers): void
    {
        usort(
            $workers,
            static fn(WorkerStatus $left, WorkerStatus $right): int => [
                $left->group,
                $left->slot,
                $left->generation,
                $left->pid,
            ] <=> [
                $right->group,
                $right->slot,
                $right->generation,
                $right->pid,
            ],
        );
    }

    private static function unhealthyState(WorkerState $state): bool
    {
        return $state === WorkerState::FAILED || $state === WorkerState::UNHEALTHY;
    }
}
