<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Supervisor\Internal;

use Infocyph\Runwire\Supervisor\Enum\WorkerState;
use Infocyph\Runwire\Supervisor\WorkerGroup;

final class ChildSet
{
    /** @param array<int, ChildRecord> $children */
    public static function hasReplacementFor(array $children, int $pid): bool
    {
        return array_any($children, fn($record) => $record->replacesPid === $pid);
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
        foreach (self::slots($groups) as [$group, $slot]) {
            $pid = $currentSlots[$group->name][$slot] ?? null;
            $record = $pid !== null ? ($children[$pid] ?? null) : null;

            if ($record === null
                || $record->generation !== $generation
                || $record->state !== WorkerState::READY) {
                return false;
            }
        }

        return array_all($children, fn($record) => !($record->generation < $generation));
    }

    /**
     * @param array<string, WorkerGroup> $groups
     * @return list<array{0: WorkerGroup, 1: int}>
     */
    public static function slots(array $groups): array
    {
        $slots = [];
        foreach ($groups as $group) {
            for ($slot = 0; $slot < $group->count; ++$slot) {
                $slots[] = [$group, $slot];
            }
        }

        return $slots;
    }
}
