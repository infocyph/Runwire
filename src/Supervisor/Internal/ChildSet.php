<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Supervisor\Internal;

use Infocyph\Runwire\Supervisor\WorkerGroup;
use Infocyph\Runwire\Supervisor\WorkerState;

final class ChildSet
{
    /** @param array<int, ChildRecord> $children */
    public static function hasReplacementFor(array $children, int $pid): bool
    {
        foreach ($children as $record) {
            if ($record->replacesPid === $pid) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, WorkerGroup> $groups
     * @param array<string, array<int, int>> $currentSlots
     * @param array<int, ChildRecord> $children
     */
    public static function reloadComplete(
        array $groups,
        array $currentSlots,
        array $children,
        int $generation,
    ): bool {
        foreach ($groups as $group) {
            for ($slot = 0; $slot < $group->count; ++$slot) {
                $pid = $currentSlots[$group->name][$slot] ?? null;
                $record = $pid !== null ? ($children[$pid] ?? null) : null;

                if ($record === null
                    || $record->generation !== $generation
                    || $record->state !== WorkerState::READY) {
                    return false;
                }
            }
        }

        foreach ($children as $record) {
            if ($record->generation < $generation) {
                return false;
            }
        }

        return true;
    }
}
