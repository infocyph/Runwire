<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Supervisor\Internal;

use Infocyph\Runwire\Supervisor\WorkerGroup;

/**
 * Tracks restart counts and rate-limited restart history for worker groups.
 */
final class RestartTracker
{
    private const int NANOS_PER_SECOND = 1_000_000_000;

    /** @var array<string, array<int, int>> */
    private array $counts = [];

    /** @var array<string, list<int>> */
    private array $history = [];

    /**
     * Return the restart count for a worker slot.
     */
    public function count(string $group, int $slot): int
    {
        return $this->counts[$group][$slot] ?? 0;
    }

    /**
     * Reserve and describe the next restart attempt, or return null when the budget is exhausted.
     */
    public function nextAttempt(WorkerGroup $group, int $slot): ?RestartAttempt
    {
        $now = (int) hrtime(true);
        $history = $this->recentHistory($group, $now);

        if (count($history) >= $group->restartPolicy->maxRestarts) {
            $this->history[$group->name] = $history;

            return null;
        }

        $history[] = $now;
        $this->history[$group->name] = $history;

        $count = $this->count($group->name, $slot) + 1;
        $this->counts[$group->name][$slot] = $count;

        return new RestartAttempt(
            count: $count,
            delaySeconds: $group->restartPolicy->backoffForAttempt(count($history)),
        );
    }

    /**
     * Initialize restart counters for all slots in a worker group.
     */
    public function register(WorkerGroup $group): void
    {
        $this->counts[$group->name] = array_fill(0, $group->count, 0);
    }

    /** @return list<int> */
    private function recentHistory(WorkerGroup $group, int $now): array
    {
        $window = (int) round($group->restartPolicy->windowSeconds * self::NANOS_PER_SECOND);
        $cutoff = $now - $window;

        return array_values(array_filter(
            $this->history[$group->name] ?? [],
            static fn(int $timestamp): bool => $timestamp >= $cutoff,
        ));
    }
}
