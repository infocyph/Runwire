<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Supervisor\Internal;

use Closure;
use Infocyph\Runwire\Supervisor\Enum\ShutdownReason;
use Infocyph\Runwire\Supervisor\Enum\SupervisorEventType;
use Infocyph\Runwire\Supervisor\Enum\WorkerExitReason;
use Infocyph\Runwire\Supervisor\Enum\WorkerState;
use Infocyph\Runwire\Supervisor\ReloadPolicy;
use Infocyph\Runwire\Supervisor\SupervisorEvent;
use Infocyph\Runwire\Supervisor\WorkerGroup;

/**
 * Coordinates bounded rolling reloads across reloadable worker slots.
 */
final class ReloadCoordinator
{
    /** @var array<string, true> */
    private array $active = [];

    private bool $failed = false;

    private int $generation = 1;

    private bool $generationReady = false;

    /** @var list<array{0: string, 1: int}> */
    private array $pending = [];

    private bool $queued = false;

    private bool $reloading = false;

    /**
     * @param Closure(WorkerGroup, int, int, int, ?int, bool, bool, ?float): void $spawnWorker
     * @param Closure(ChildRecord, WorkerState, bool, ShutdownReason, ?float): void $stopChild
     * @param Closure(SupervisorEvent): void $emit
     * @param Closure(string): mixed $cancelRestart
     * @param Closure(string, int): int $restartCount
     * @param Closure(): float $now
     */
    public function __construct(
        private readonly ReloadPolicy $policy,
        private readonly Closure $spawnWorker,
        private readonly Closure $stopChild,
        private readonly Closure $emit,
        private readonly Closure $cancelRestart,
        private readonly Closure $restartCount,
        private readonly Closure $now,
    ) {}

    /**
     * @param array<int, ChildRecord> $children
     * @param array<string, array<int, int>> $currentSlots
     */
    public function abort(ChildRecord $failed, array $children, array $currentSlots): void
    {
        if (!$this->reloading) {
            return;
        }

        $targetGeneration = $this->generation;
        foreach (array_keys($this->active) as $key) {
            ($this->cancelRestart)($key);
        }

        $this->failed = true;
        $this->generationReady = false;
        $this->pending = [];
        $this->active = [];
        $this->queued = false;
        $this->reloading = false;

        foreach ($children as $record) {
            if ($record->generation !== $targetGeneration || $record->expectedStop) {
                continue;
            }
            $currentPid = $currentSlots[$record->group->name][$record->slot] ?? null;
            if ($currentPid === $record->pid) {
                continue;
            }
            ($this->stopChild)(
                $record,
                WorkerState::STOPPING,
                false,
                ShutdownReason::FATAL_RUNTIME_ERROR,
                null,
            );
        }

        ($this->emit)(new SupervisorEvent(
            SupervisorEventType::RELOAD_FAILED,
            ($this->now)(),
            group: $failed->group->name,
            slot: $failed->slot,
            pid: $failed->pid,
            generation: $targetGeneration,
            exitReason: WorkerExitReason::RESTART_BUDGET_EXHAUSTED,
        ));
    }

    /**
     * Determine whether a worker slot is actively participating in the reload.
     */
    public function active(string $key): bool
    {
        return isset($this->active[$key]);
    }

    /**
     * @param array<string, WorkerGroup> $groups
     * @param array<string, array<int, int>> $currentSlots
     * @param array<int, ChildRecord> $children
     */
    public function checkCompletion(array $groups, array $currentSlots, array $children): void
    {
        if (!$this->reloading) {
            return;
        }

        $this->pump($groups, $currentSlots, $children);
        if ($this->pending !== [] || $this->active !== []) {
            return;
        }
        if (!ChildSet::reloadComplete($groups, $currentSlots, $children, $this->generation)) {
            return;
        }

        $this->reloading = false;
        $this->checkGenerationReadiness($groups, $currentSlots, $children);
        ($this->emit)(new SupervisorEvent(
            SupervisorEventType::RELOAD_COMPLETED,
            ($this->now)(),
            generation: $this->generation,
        ));
        if ($this->queued) {
            $this->queued = false;
            $this->begin($groups, $currentSlots, $children);
        }
    }

    /**
     * @param array<string, WorkerGroup> $groups
     * @param array<string, array<int, int>> $currentSlots
     * @param array<int, ChildRecord> $children
     */
    public function checkGenerationReadiness(array $groups, array $currentSlots, array $children): void
    {
        if ($this->generationReady) {
            return;
        }
        if (!ChildSet::generationReady($groups, $currentSlots, $children, $this->generation)) {
            return;
        }

        $this->generationReady = true;
        ($this->emit)(new SupervisorEvent(
            SupervisorEventType::GENERATION_READY,
            ($this->now)(),
            generation: $this->generation,
        ));
    }

    /**
     * @param array<string, WorkerGroup> $groups
     * @param array<string, array<int, int>> $currentSlots
     * @param array<int, ChildRecord> $children
     */
    public function completeSlot(
        string $group,
        int $slot,
        array $groups,
        array $currentSlots,
        array $children,
        ?int $replacedPid = null,
    ): void {
        $key = self::slotKey($group, $slot);
        if (!isset($this->active[$key])) {
            return;
        }

        $currentPid = $currentSlots[$group][$slot] ?? null;
        $current = $currentPid === null ? null : ($children[$currentPid] ?? null);
        if ($current === null || $current->generation !== $this->generation || !$current->state->serving()) {
            return;
        }

        if ($replacedPid !== null && $current->replacesPid === $replacedPid) {
            $current->replacesPid = null;
        }
        unset($this->active[$key]);
        $this->checkGenerationReadiness($groups, $currentSlots, $children);
        $this->pump($groups, $currentSlots, $children);
    }

    /**
     * Return the configured graceful drain timeout for replaced workers.
     */
    public function drainTimeoutSeconds(): float
    {
        return $this->policy->drainTimeoutSeconds;
    }

    /**
     * Determine whether the current reload attempt has failed.
     */
    public function failed(): bool
    {
        return $this->failed;
    }

    /**
     * Return the current worker generation number.
     */
    public function generation(): int
    {
        return $this->generation;
    }

    /**
     * Determine whether the current generation is fully serving.
     */
    public function generationReady(): bool
    {
        return $this->generationReady;
    }

    /**
     * Determine whether another reload request is queued.
     */
    public function queued(): bool
    {
        return $this->queued;
    }

    /**
     * Determine whether a rolling reload is currently active.
     */
    public function reloading(): bool
    {
        return $this->reloading;
    }

    /**
     * Return the readiness timeout applied to replacement workers.
     */
    public function replacementReadyTimeoutSeconds(): float
    {
        return $this->policy->replacementReadyTimeoutSeconds;
    }

    /**
     * @param array<string, WorkerGroup> $groups
     * @param array<string, array<int, int>> $currentSlots
     * @param array<int, ChildRecord> $children
     */
    public function request(
        bool $running,
        bool $stopping,
        array $groups,
        array $currentSlots,
        array $children,
    ): void {
        if (!$running || $stopping) {
            return;
        }
        if ($this->reloading) {
            $this->queued = true;

            return;
        }

        $this->begin($groups, $currentSlots, $children);
    }

    /**
     * Clear pending and active reload state during supervisor shutdown.
     */
    public function resetForStop(): void
    {
        $this->active = [];
        $this->pending = [];
        $this->queued = false;
        $this->reloading = false;
    }

    private static function slotKey(string $group, int $slot): string
    {
        return $group . ':' . $slot;
    }

    /**
     * @param array<string, WorkerGroup> $groups
     * @param array<string, array<int, int>> $currentSlots
     * @param array<int, ChildRecord> $children
     */
    private function begin(array $groups, array $currentSlots, array $children): void
    {
        $slots = ChildSet::slots($groups, reloadableOnly: true);
        if ($slots === []) {
            ($this->emit)(new SupervisorEvent(
                SupervisorEventType::RELOAD_COMPLETED,
                ($this->now)(),
                generation: $this->generation,
            ));

            return;
        }

        $this->reloading = true;
        $this->failed = false;
        $this->generationReady = false;
        ++$this->generation;
        $this->pending = array_map(
            static fn(array $slot): array => [$slot[0]->name, $slot[1]],
            $slots,
        );
        $this->active = [];
        ($this->emit)(new SupervisorEvent(
            SupervisorEventType::RELOAD_STARTED,
            ($this->now)(),
            generation: $this->generation,
        ));
        $this->pump($groups, $currentSlots, $children);
    }

    /**
     * @param array<string, WorkerGroup> $groups
     * @param array<string, array<int, int>> $currentSlots
     * @param array<int, ChildRecord> $children
     */
    private function pump(array $groups, array $currentSlots, array $children): void
    {
        if (!$this->reloading) {
            return;
        }
        if (ChildSet::unavailableReloadableSlots($groups, $currentSlots, $children) > $this->policy->maxUnavailable) {
            return;
        }

        while ($this->pending !== [] && count($this->active) < $this->policy->maxSurge) {
            [$groupName, $slot] = array_shift($this->pending);
            $group = $groups[$groupName] ?? null;
            if ($group === null || !$group->reloadable) {
                continue;
            }

            $key = self::slotKey($groupName, $slot);
            $this->active[$key] = true;
            ($this->cancelRestart)($key);
            $oldPid = $currentSlots[$groupName][$slot] ?? null;
            ($this->spawnWorker)(
                $group,
                $slot,
                $this->generation,
                ($this->restartCount)($groupName, $slot),
                $oldPid,
                $oldPid === null,
                false,
                $this->policy->replacementReadyTimeoutSeconds,
            );
        }
    }
}
