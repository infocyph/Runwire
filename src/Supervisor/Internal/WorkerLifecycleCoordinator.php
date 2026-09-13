<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Supervisor\Internal;

use Closure;
use Infocyph\Runwire\Loop\LoopInterface;
use Infocyph\Runwire\Metrics\RuntimeMetricsSnapshot;
use Infocyph\Runwire\Supervisor\Enum\ShutdownReason;
use Infocyph\Runwire\Supervisor\Enum\SupervisorEventType;
use Infocyph\Runwire\Supervisor\Enum\WorkerState;
use Infocyph\Runwire\Supervisor\WorkerGroup;

final readonly class WorkerLifecycleCoordinator
{
    /**
     * @param Closure(SupervisorEventType, ChildRecord, ?int, ?int, ?bool): void $emitWorker
     * @param Closure(ChildRecord, WorkerState, bool, ShutdownReason, ?float): void $stopChild
     */
    public function __construct(
        private LoopInterface $loop,
        private ReloadCoordinator $reload,
        private Closure $emitWorker,
        private Closure $stopChild,
    ) {}

    /**
     * @param array<int, ChildRecord> $children
     * @param array<string, array<int, int>> $currentSlots
     * @param array<string, WorkerGroup> $groups
     */
    public function handle(
        ChildRecord $record,
        string $message,
        array $children,
        array &$currentSlots,
        array $groups,
    ): void {
        if ($message === 'R') {
            $this->ready($record, $children, $currentSlots, $groups);

            return;
        }
        if ($message === 'B' || $message === 'I') {
            $this->activity($record, $message);

            return;
        }
        if ($message === 'U') {
            $this->unhealthy($record);

            return;
        }
        if (str_starts_with($message, 'M:')) {
            $this->metrics($record, substr($message, 2));

            return;
        }
        if (str_starts_with($message, 'D:')) {
            ($this->emitWorker)(SupervisorEventType::REQUEST_DEADLINE_EXCEEDED, $record, null, null, null);

            return;
        }
        if (str_starts_with($message, 'X:')) {
            $this->shutdownReason($record, $message);
        }
    }

    private function activity(ChildRecord $record, string $message): void
    {
        if ($record->expectedStop || !$record->state->serving()) {
            return;
        }

        if ($message === 'B') {
            $record->state = WorkerState::BUSY;
            $record->busySinceNs ??= self::nowNanoseconds();

            return;
        }

        $record->state = WorkerState::IDLE;
        $record->busySinceNs = null;
    }

    private function metrics(ChildRecord $record, string $payload): void
    {
        $decoded = json_decode($payload, true, 16);
        if (!is_array($decoded) || array_is_list($decoded)) {
            return;
        }

        /** @var array<string, mixed> $decoded */
        $snapshot = RuntimeMetricsSnapshot::fromArray($decoded);
        if ($snapshot !== null) {
            $record->metrics = $snapshot;
        }
    }

    private static function nowNanoseconds(): int
    {
        $now = hrtime(true);

        return is_int($now) ? $now : (int) $now;
    }

    /**
     * @param array<int, ChildRecord> $children
     * @param array<string, array<int, int>> $currentSlots
     * @param array<string, WorkerGroup> $groups
     */
    private function ready(
        ChildRecord $record,
        array $children,
        array &$currentSlots,
        array $groups,
    ): void {
        if ($record->state !== WorkerState::STARTING) {
            return;
        }

        $record->busySinceNs = null;
        $record->state = WorkerState::READY;
        ReadinessChannel::ready($this->loop, $record);
        ($this->emitWorker)(SupervisorEventType::WORKER_READY, $record, null, null, null);

        if ($record->replacesPid !== null) {
            $this->replacementReady($record, $children, $currentSlots, $groups);
        } else {
            $this->standaloneReady($record, $currentSlots, $groups, $children);
        }

        $this->reload->checkGenerationReadiness($groups, $currentSlots, $children);
        $this->reload->checkCompletion($groups, $currentSlots, $children);
    }

    /**
     * @param array<int, ChildRecord> $children
     * @param array<string, array<int, int>> $currentSlots
     * @param array<string, WorkerGroup> $groups
     */
    private function replacementReady(
        ChildRecord $record,
        array $children,
        array &$currentSlots,
        array $groups,
    ): void {
        $replacedPid = $record->replacesPid;
        if ($replacedPid === null) {
            return;
        }

        $currentSlots[$record->group->name][$record->slot] = $record->pid;
        $old = $children[$replacedPid] ?? null;
        if ($old !== null) {
            $this->stopReplacedWorker($record, $old);

            return;
        }
        if (!$this->reload->reloading()) {
            return;
        }

        $record->replacesPid = null;
        $this->reload->completeSlot(
            $record->group->name,
            $record->slot,
            $groups,
            $currentSlots,
            $children,
        );
    }

    private function shutdownReason(ChildRecord $record, string $message): void
    {
        $reason = ShutdownReason::tryFrom(substr($message, 2));
        if ($reason !== null) {
            $record->shutdownReason = $reason;
        }
    }

    /**
     * @param array<string, array<int, int>> $currentSlots
     * @param array<string, WorkerGroup> $groups
     * @param array<int, ChildRecord> $children
     */
    private function standaloneReady(
        ChildRecord $record,
        array &$currentSlots,
        array $groups,
        array $children,
    ): void {
        if (($currentSlots[$record->group->name][$record->slot] ?? null) !== null) {
            return;
        }

        $currentSlots[$record->group->name][$record->slot] = $record->pid;
        if ($this->reload->reloading()) {
            $this->reload->completeSlot(
                $record->group->name,
                $record->slot,
                $groups,
                $currentSlots,
                $children,
            );
        }
    }

    private function stopReplacedWorker(ChildRecord $replacement, ChildRecord $old): void
    {
        $reason = $replacement->recycleReplacement
            ? ShutdownReason::MANUAL_RECYCLE
            : ShutdownReason::DEPLOYMENT_RELOAD;
        $timeout = $replacement->recycleReplacement
            ? $old->group->shutdownTimeoutSeconds
            : $this->reload->drainTimeoutSeconds();
        ($this->stopChild)($old, WorkerState::DRAINING, false, $reason, $timeout);
    }

    private function unhealthy(ChildRecord $record): void
    {
        if ($record->expectedStop) {
            return;
        }

        $record->busySinceNs = null;
        $record->state = WorkerState::UNHEALTHY;
        ($this->emitWorker)(SupervisorEventType::WORKER_UNHEALTHY, $record, null, null, null);
    }
}
