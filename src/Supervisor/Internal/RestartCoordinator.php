<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Supervisor\Internal;

use Closure;
use Infocyph\Runwire\Exception\SupervisorException;
use Infocyph\Runwire\Loop\LoopInterface;
use Infocyph\Runwire\Supervisor\Enum\SupervisorEventType;
use Infocyph\Runwire\Supervisor\Enum\WorkerExitReason;
use Infocyph\Runwire\Supervisor\SupervisorEvent;
use Infocyph\Runwire\Supervisor\WorkerGroup;

final class RestartCoordinator
{
    private readonly RestartTracker $tracker;

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
        $this->tracker = new RestartTracker();
        $this->reasonCounts = self::reasonCounters();
    }

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

    public function cancelAll(): void
    {
        foreach ($this->timers as $timerId) {
            $this->loop->cancel($timerId);
        }
        $this->timers = [];
    }

    public function count(string $group, int $slot): int
    {
        return $this->tracker->count($group, $slot);
    }

    public function pendingCount(): int
    {
        return count($this->timers);
    }

    /** @return array<string, int> */
    public function reasonCounts(): array
    {
        return $this->reasonCounts;
    }

    public function register(WorkerGroup $group): void
    {
        $this->tracker->register($group);
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

        $attempt = $this->tracker->nextAttempt($record->group, $record->slot);
        if ($attempt === null) {
            $this->handleExhausted($record, $key, $currentSlots, $children);

            return;
        }

        $this->queueAttempt($record, $key, $attempt);
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

    private function queueAttempt(ChildRecord $record, string $key, RestartAttempt $attempt): void
    {
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
            restartCount: $attempt->count,
            restartDelaySeconds: $attempt->delaySeconds,
            replacesPid: $record->replacesPid,
            exitReason: $reason,
        ));

        $this->timers[$key] = $this->loop->delay(
            $attempt->delaySeconds,
            function () use ($record, $attempt, $key, $restartGeneration): void {
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
                    $attempt->count,
                    $record->replacesPid,
                    $record->replacesPid === null,
                    $record->recycleReplacement,
                    $reloadReplacement ? $this->reload->replacementReadyTimeoutSeconds() : null,
                );
            },
        );
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
