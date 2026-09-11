<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Supervisor\Internal;

use Infocyph\Runwire\Supervisor\SupervisorStatus;
use Infocyph\Runwire\Supervisor\WorkerState;
use Infocyph\Runwire\Supervisor\WorkerStatus;

final class SupervisorStatusBuilder
{
    private const int NANOS_PER_SECOND = 1_000_000_000;

    /**
     * @param array<int, ChildRecord> $children
     * @param array<string, array<int, int>> $currentSlots
     */
    public static function build(
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
    ): SupervisorStatus {
        $now = hrtime(true) / self::NANOS_PER_SECOND;
        $workers = [];
        $ready = 0;
        $current = 0;

        foreach ($children as $record) {
            $isCurrent = ($currentSlots[$record->group->name][$record->slot] ?? null) === $record->pid;
            $current += $isCurrent ? 1 : 0;
            $ready += $record->state === WorkerState::READY ? 1 : 0;
            $started = $record->startedAtNs / self::NANOS_PER_SECOND;
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
            );
        }

        usort(
            $workers,
            static fn (WorkerStatus $left, WorkerStatus $right): int => [
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

        $started = $startedAtNs === null ? null : $startedAtNs / self::NANOS_PER_SECOND;

        return new SupervisorStatus(
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
        );
    }
}
