<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Supervisor\Internal;

use Infocyph\Runwire\Supervisor\Enum\ChildExitAction;
use Infocyph\Runwire\Supervisor\Enum\ShutdownReason;
use Infocyph\Runwire\Supervisor\Enum\WorkerExitReason;
use Infocyph\Runwire\Supervisor\Enum\WorkerState;

/**
 * Computes the supervisor transition to apply after a child process exits.
 */
final readonly class ChildExitTransition
{
    private function __construct(
        public ChildExitAction $action,
        public bool $plannedRecycle,
        public WorkerExitReason $reason,
    ) {}

    /**
     * Evaluate a child exit and return the resulting supervisor action and reason.
     */
    public static function evaluate(
        ChildRecord $record,
        ?int $exitCode,
        ?int $termSignal,
        bool $hasReplacement,
        bool $supervisorStopping,
        bool $childrenEmptyAfterRemoval,
    ): self {
        $previousState = $record->state;
        $plannedRecycle = !$record->expectedStop && $exitCode === WorkerChildRuntime::RECYCLE_EXIT_CODE;
        if ($plannedRecycle) {
            $record->expectedStop = true;
        }

        $reason = $record->exitReason ?? self::classify(
            $record,
            $previousState,
            $exitCode,
            $termSignal,
            $plannedRecycle,
        );
        $record->exitReason = $reason;
        $record->state = WorkerState::EXITED;

        if ($supervisorStopping) {
            return new self(
                $childrenEmptyAfterRemoval ? ChildExitAction::STOP_LOOP : ChildExitAction::NONE,
                $plannedRecycle,
                $reason,
            );
        }
        if ($plannedRecycle && !$hasReplacement) {
            return new self(ChildExitAction::SPAWN_RECYCLE, true, $reason);
        }
        if ($record->expectedStop || $hasReplacement) {
            return new self(ChildExitAction::CHECK_RELOAD, $plannedRecycle, $reason);
        }

        return new self(ChildExitAction::RESTART, false, $reason);
    }

    private static function classify(
        ChildRecord $record,
        WorkerState $previousState,
        ?int $exitCode,
        ?int $termSignal,
        bool $plannedRecycle,
    ): WorkerExitReason {
        if ($plannedRecycle) {
            return WorkerExitReason::PLANNED_RECYCLE;
        }
        if ($record->expectedStop) {
            return match ($record->shutdownReason) {
                ShutdownReason::DEPLOYMENT_RELOAD => WorkerExitReason::PLANNED_RELOAD,
                ShutdownReason::MANUAL_RECYCLE,
                ShutdownReason::RECYCLE_LIFETIME,
                ShutdownReason::RECYCLE_MEMORY_LIMIT,
                ShutdownReason::RECYCLE_REQUEST_LIMIT => WorkerExitReason::PLANNED_RECYCLE,
                default => WorkerExitReason::NORMAL_SHUTDOWN,
            };
        }
        if ($exitCode === WorkerChildRuntime::WARMUP_FAILURE_EXIT_CODE) {
            return WorkerExitReason::WARMUP_FAILURE;
        }
        if ($previousState === WorkerState::STARTING) {
            return WorkerExitReason::STARTUP_FAILURE;
        }
        if ($termSignal !== null) {
            return WorkerExitReason::SIGNAL_EXIT;
        }
        if ($exitCode === 70) {
            return WorkerExitReason::APPLICATION_FATAL;
        }

        return WorkerExitReason::CRASH;
    }
}
