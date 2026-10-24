<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Supervisor\Internal;

use Closure;
use Infocyph\Runwire\Exception\SupervisorException;
use Infocyph\Runwire\Internal\MonotonicTime;
use Infocyph\Runwire\Loop\LoopInterface;
use Infocyph\Runwire\Supervisor\Enum\SupervisorEventType;
use Infocyph\Runwire\Supervisor\Enum\WorkerExitReason;
use Infocyph\Runwire\Supervisor\SupervisorEvent;
use Infocyph\Runwire\Supervisor\WorkerGroup;

/**
 * Schedules worker restarts with bounded backoff and reload-aware failure handling.
 */
final class RestartCoordinator
{
    /** @var array<string, array<int, int>> */
    private array $counts = [];

    /** @var array<string, list<int>> */
    private array $history = [];

    /** @var array<string, int> */
    private array $reasonCounts;

    /** @var array<string, int> */
    private array $timers = [];

    /**
     * @param Closure(WorkerGroup, int, int, int, ?int, bool, bool, ?float): void $spawnWorker
     * @param Closure(SupervisorEvent): void $emit
     * @param Closure(SupervisorException): void $fail
     * @param Closure(): float $now
     * @param Closure(): bool $isStopping
     */
    public function __construct(
        private readonly LoopInterface $loop,
        private readonly ReloadCoordinator $reload,
        private readonly Closure $spawnWorker,
        private readonly Closure $emit,
        private readonly Closure $fail,
        private readonly Closure $now,
        private readonly Closure $isStopping,
    ) {
        $this->reasonCounts = self::reasonCounters();
    }

    /**
     * Cancel a pending restart timer for one worker slot.
     */
    public function cancel(string $key): null
    {
        $timerId = $this->timers[$key] ?? null;
        if ($timerId === null) {
            return null;
        }

        $this->loop->cancel($timerId);
        unset($this->timers[$key]);

        return null;
    }

    /**
     * Cancel every pending worker restart timer.
     */
    public function cancelAll(): void
    {
        foreach ($this->timers as $timerId) {
            $this->loop->cancel($timerId);
        }
        $this->timers = [];
    }

    /**
     * Return the restart count tracked for a worker slot.
     */
    public function count(string $group, int $slot): int
    {
        return $this->counts[$group][$slot] ?? 0;
    }

    /**
     * Return the number of restart timers currently pending.
     */
    public function pendingCount(): int
    {
        return count($this->timers);
    }

    /** @return array<string, int> */
    public function reasonCounts(): array
    {
        return $this->reasonCounts;
    }

    /**
     * Register restart tracking state for a worker group.
     */
    public function register(WorkerGroup $group): void
    {
        $this->counts[$group->name] = array_fill(0, $group->count, 0);
    }

    /**
     * @param array<string, array<int, int>> $currentSlots
     * @param array<int, ChildRecord> $children
     */
    public function schedule(ChildRecord $record, array $currentSlots, array $children): void
    {
        $key = self::slotKey($record->group->name, $record->slot);
        if (isset($this->timers[$key])) {
            return;
        }

        $delaySeconds = $this->reserveRestart($record->group, $record->slot);
        if ($delaySeconds === null) {
            $this->handleExhausted($record, $key, $currentSlots, $children);

            return;
        }

        $this->queueAttempt(
            $record,
            $key,
            $this->count($record->group->name, $record->slot),
            $delaySeconds,
        );
    }

    /** @return array<string, int> */
    private static function reasonCounters(): array
    {
        $counts = [];
        foreach (WorkerExitReason::cases() as $reason) {
            $counts[$reason->value] = 0;
        }

        return $counts;
    }

    private static function slotKey(string $group, int $slot): string
    {
        return $group . ':' . $slot;
    }

    /**
     * @param array<string, array<int, int>> $currentSlots
     * @param array<int, ChildRecord> $children
     */
    private function handleExhausted(
        ChildRecord $record,
        string $key,
        array $currentSlots,
        array $children,
    ): void {
        ++$this->reasonCounts[WorkerExitReason::RESTART_BUDGET_EXHAUSTED->value];
        $currentPid = $currentSlots[$record->group->name][$record->slot] ?? null;
        $current = $currentPid === null ? null : ($children[$currentPid] ?? null);
        if ($this->shouldAbortReload($record, $key, $current)) {
            $this->reload->abort($record, $children, $currentSlots);

            return;
        }
        if ($record->recycleReplacement && $current !== null) {
            return;
        }

        ($this->fail)(new SupervisorException(sprintf(
            'Restart budget exhausted for worker group "%s".',
            $record->group->name,
        )));
    }

    private function queueAttempt(
        ChildRecord $record,
        string $key,
        int $restartCount,
        float $delaySeconds,
    ): void {
        $reason = $record->exitReason ?? WorkerExitReason::CRASH;
        ++$this->reasonCounts[$reason->value];
        $restartGeneration = $record->group->reloadable
            ? max($record->generation, $this->reload->generation())
            : $record->generation;
        ($this->emit)(new SupervisorEvent(
            SupervisorEventType::WORKER_RESTART_SCHEDULED,
            ($this->now)(),
            group: $record->group->name,
            slot: $record->slot,
            generation: $restartGeneration,
            restartCount: $restartCount,
            restartDelaySeconds: $delaySeconds,
            replacesPid: $record->replacesPid,
            exitReason: $reason,
        ));

        $this->timers[$key] = $this->loop->delay(
            $delaySeconds,
            function () use ($record, $restartCount, $key, $restartGeneration): void {
                unset($this->timers[$key]);
                if (($this->isStopping)()) {
                    return;
                }
                if ($this->reload->failed() && $this->reload->active($key)) {
                    return;
                }

                $reloadReplacement = $this->reload->reloading()
                    && $this->reload->active($key)
                    && $restartGeneration === $this->reload->generation();
                ($this->spawnWorker)(
                    $record->group,
                    $record->slot,
                    $restartGeneration,
                    $restartCount,
                    $record->replacesPid,
                    $record->replacesPid === null,
                    $record->recycleReplacement,
                    $reloadReplacement ? $this->reload->replacementReadyTimeoutSeconds() : null,
                );
            },
        );
    }

    /** @return list<int> */
    private function recentRestartHistory(WorkerGroup $group, int $now): array
    {
        $window = MonotonicTime::secondsToNanoseconds($group->restartPolicy->windowSeconds);
        $cutoff = $now - $window;

        return array_values(array_filter(
            $this->history[$group->name] ?? [],
            static fn(int $timestamp): bool => $timestamp >= $cutoff,
        ));
    }

    private function reserveRestart(WorkerGroup $group, int $slot): ?float
    {
        $now = MonotonicTime::nowNanoseconds();
        $history = $this->recentRestartHistory($group, $now);
        if (count($history) >= $group->restartPolicy->maxRestarts) {
            $this->history[$group->name] = $history;

            return null;
        }

        $history[] = $now;
        $this->history[$group->name] = $history;
        $this->counts[$group->name][$slot] = $this->count($group->name, $slot) + 1;

        return $group->restartPolicy->backoffForAttempt(count($history));
    }

    private function shouldAbortReload(ChildRecord $record, string $key, ?ChildRecord $current): bool
    {
        return $this->reload->reloading()
            && $this->reload->active($key)
            && $record->generation === $this->reload->generation()
            && $current !== null
            && $current->state->serving();
    }
}
