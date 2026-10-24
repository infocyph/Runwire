<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Supervisor\Internal;

use Infocyph\Runwire\Supervisor\WorkerGroup;

/**
 * Provides derived state and slot queries across supervised child records.
 */
final class ChildSet
{
    /**
     * @param array<string, WorkerGroup> $groups
     * @param array<string, array<int, int>> $currentSlots
     * @param array<int, ChildRecord> $children
     */
    public static function generationReady(
        array $groups,
        array $currentSlots,
        array $children,
        int $generation,
    ): bool {
        foreach (self::slots($groups) as [$group, $slot]) {
            $pid = $currentSlots[$group->name][$slot] ?? null;
            $record = $pid !== null ? ($children[$pid] ?? null) : null;
            if ($record === null || !$record->state->serving()) {
                return false;
            }
            if ($group->reloadable && $record->generation !== $generation) {
                return false;
            }
        }

        return true;
    }

    /** @param array<int, ChildRecord> $children */
    public static function hasReplacementFor(array $children, int $pid): bool
    {
        return array_any($children, fn(ChildRecord $record): bool => $record->replacesPid === $pid);
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
        foreach (self::slots($groups, reloadableOnly: true) as [$group, $slot]) {
            $pid = $currentSlots[$group->name][$slot] ?? null;
            $record = $pid !== null ? ($children[$pid] ?? null) : null;

            if ($record === null
                || $record->generation !== $generation
                || !$record->state->serving()) {
                return false;
            }
        }

        return array_all(
            $children,
            static fn(ChildRecord $record): bool => !$record->group->reloadable
                || $record->generation >= $generation,
        );
    }

    /**
     * @param array<string, WorkerGroup> $groups
     * @return list<array{0: WorkerGroup, 1: int}>
     */
    public static function slots(array $groups, bool $reloadableOnly = false): array
    {
        $slots = [];
        foreach ($groups as $group) {
            if ($reloadableOnly && !$group->reloadable) {
                continue;
            }
            for ($slot = 0; $slot < $group->count; ++$slot) {
                $slots[] = [$group, $slot];
            }
        }

        return $slots;
    }

    /**
     * @param array<string, WorkerGroup> $groups
     * @param array<string, array<int, int>> $currentSlots
     * @param array<int, ChildRecord> $children
     */
    public static function unavailableReloadableSlots(
        array $groups,
        array $currentSlots,
        array $children,
    ): int {
        $unavailable = 0;
        foreach (self::slots($groups, reloadableOnly: true) as [$group, $slot]) {
            $pid = $currentSlots[$group->name][$slot] ?? null;
            $record = $pid !== null ? ($children[$pid] ?? null) : null;
            if ($record === null || !$record->state->serving()) {
                ++$unavailable;
            }
        }

        return $unavailable;
    }
}
