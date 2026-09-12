<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Supervisor\Internal;

use Infocyph\Runwire\Supervisor\Enum\ChildExitAction;
use Infocyph\Runwire\Supervisor\Enum\WorkerState;

final readonly class ChildExitTransition
{
    private function __construct(
        public ChildExitAction $action,
        public bool $plannedRecycle,
    ) {}

    public static function evaluate(
        ChildRecord $record,
        ?int $exitCode,
        bool $hasReplacement,
        bool $supervisorStopping,
        bool $childrenEmptyAfterRemoval,
    ): self {
        $plannedRecycle = !$record->expectedStop && $exitCode === WorkerChildRuntime::RECYCLE_EXIT_CODE;
        if ($plannedRecycle) {
            $record->expectedStop = true;
            $record->state = WorkerState::DRAINING;
        } elseif (!$record->expectedStop) {
            $record->state = WorkerState::FAILED;
        }

        if ($supervisorStopping) {
            return new self(
                $childrenEmptyAfterRemoval ? ChildExitAction::STOP_LOOP : ChildExitAction::NONE,
                $plannedRecycle,
            );
        }
        if ($plannedRecycle && !$hasReplacement) {
            return new self(ChildExitAction::SPAWN_RECYCLE, true);
        }
        if ($record->expectedStop || $hasReplacement) {
            return new self(ChildExitAction::CHECK_RELOAD, $plannedRecycle);
        }

        return new self(ChildExitAction::RESTART, false);
    }
}
