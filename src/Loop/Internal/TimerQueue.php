<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Loop\Internal;

use Closure;
use InvalidArgumentException;
use OverflowException;

final class TimerQueue
{
    private const int COMPACTION_FLOOR = 64;

    private const int NANOS_PER_SECOND = 1_000_000_000;

    private int $cancelledEntries = 0;

    /** @var list<array{id: int, deadline: int}> */
    private array $heap = [];

    /** @var array<int, array{deadline: int, interval: int, callback: Closure}> */
    private array $timers = [];

    public function addDelay(int $id, float $seconds, Closure $callback): void
    {
        $this->add($id, $seconds, 0, $callback);
    }

    public function addRepeat(int $id, float $seconds, Closure $callback): void
    {
        $interval = $this->secondsToNanoseconds($seconds, false);
        $this->add($id, $seconds, $interval, $callback);
    }

    public function cancel(int $id): bool
    {
        if (!isset($this->timers[$id])) {
            return false;
        }

        unset($this->timers[$id]);
        ++$this->cancelledEntries;
        $this->compactIfNeeded();

        return true;
    }

    public function consumeOneShot(int $id): void
    {
        unset($this->timers[$id]);
    }

    public function count(): int
    {
        return count($this->timers);
    }

    public function hasTimers(): bool
    {
        return $this->timers !== [];
    }

    public function nextDeadline(): ?int
    {
        while (($entry = $this->peek()) !== null) {
            $timer = $this->timers[$entry['id']] ?? null;
            if ($timer !== null && $timer['deadline'] === $entry['deadline']) {
                return $entry['deadline'];
            }

            $this->pop();
            if ($timer === null && $this->cancelledEntries > 0) {
                --$this->cancelledEntries;
            }
        }

        return null;
    }

    public function rescheduleRepeat(int $id): void
    {
        $timer = $this->timers[$id] ?? null;
        if ($timer === null || $timer['interval'] === 0) {
            return;
        }

        $deadline = $this->nextRepeatDeadline(
            previousDeadline: $timer['deadline'],
            interval: $timer['interval'],
            now: hrtime(true),
        );
        $this->timers[$id]['deadline'] = $deadline;
        $this->push($id, $deadline);
    }

    /** @return list<int> */
    public function takeDue(): array
    {
        $now = hrtime(true);
        $due = [];

        while (($next = $this->peek()) !== null && $next['deadline'] <= $now) {
            $entry = $this->pop();
            if ($entry === null) {
                break;
            }

            $timer = $this->timers[$entry['id']] ?? null;
            if ($timer !== null && $timer['deadline'] === $entry['deadline']) {
                $due[] = $entry['id'];

                continue;
            }

            if ($timer === null && $this->cancelledEntries > 0) {
                --$this->cancelledEntries;
            }
        }

        return $due;
    }

    /** @return array{deadline: int, interval: int, callback: Closure}|null */
    public function timer(int $id): ?array
    {
        return $this->timers[$id] ?? null;
    }

    private function add(int $id, float $seconds, int $interval, Closure $callback): void
    {
        $delay = $this->secondsToNanoseconds($seconds, true);
        $now = hrtime(true);

        if ($delay > PHP_INT_MAX - $now) {
            throw new OverflowException('Timer deadline exceeds the platform integer range.');
        }

        $deadline = $now + $delay;
        $this->timers[$id] = [
            'deadline' => $deadline,
            'interval' => $interval,
            'callback' => $callback,
        ];
        $this->push($id, $deadline);
    }

    private function compactIfNeeded(): void
    {
        $heapSize = count($this->heap);
        if ($this->cancelledEntries < self::COMPACTION_FLOOR
            || $this->cancelledEntries * 2 < $heapSize) {
            return;
        }

        $this->heap = [];
        foreach ($this->timers as $id => $timer) {
            $this->push($id, $timer['deadline']);
        }

        $this->cancelledEntries = 0;
    }

    /**
     * @param array{id: int, deadline: int} $left
     * @param array{id: int, deadline: int} $right
     */
    private function less(array $left, array $right): bool
    {
        return $left['deadline'] < $right['deadline']
            || ($left['deadline'] === $right['deadline'] && $left['id'] < $right['id']);
    }

    private function nextRepeatDeadline(int $previousDeadline, int $interval, int $now): int
    {
        if ($previousDeadline > PHP_INT_MAX - $interval) {
            throw new OverflowException('Repeating timer deadline exceeds the platform integer range.');
        }

        $next = $previousDeadline + $interval;
        if ($next > $now) {
            return $next;
        }

        $missed = intdiv($now - $previousDeadline, $interval) + 1;
        if ($missed > intdiv(PHP_INT_MAX - $previousDeadline, $interval)) {
            throw new OverflowException('Repeating timer deadline exceeds the platform integer range.');
        }

        return $previousDeadline + ($missed * $interval);
    }

    /** @return array{id: int, deadline: int}|null */
    private function peek(): ?array
    {
        return $this->heap[0] ?? null;
    }

    /** @return array{id: int, deadline: int}|null */
    private function pop(): ?array
    {
        if ($this->heap === []) {
            return null;
        }

        $root = $this->heap[0];
        $last = array_pop($this->heap);
        if ($this->heap === []) {
            return $root;
        }

        $this->siftDown($last);

        return $root;
    }

    private function push(int $id, int $deadline): void
    {
        $entry = ['id' => $id, 'deadline' => $deadline];
        $index = count($this->heap);

        while ($index > 0) {
            $parent = intdiv($index - 1, 2);
            if (!$this->less($entry, $this->heap[$parent])) {
                break;
            }

            $this->heap[$index] = $this->heap[$parent];
            $index = $parent;
        }

        $this->heap[$index] = $entry;
    }

    private function secondsToNanoseconds(float $seconds, bool $allowZero): int
    {
        if (!is_finite($seconds) || $seconds < 0 || (!$allowZero && $seconds <= 0)) {
            throw new InvalidArgumentException(
                $allowZero
                    ? 'Timer delay must be a finite non-negative number.'
                    : 'Repeating timer interval must be a finite positive number.',
            );
        }

        if ($seconds > PHP_INT_MAX / self::NANOS_PER_SECOND) {
            throw new OverflowException('Timer duration exceeds the platform integer range.');
        }

        $nanoseconds = (int) round($seconds * self::NANOS_PER_SECOND);
        if (!$allowZero && $nanoseconds === 0) {
            throw new InvalidArgumentException('Repeating timer interval is below timer resolution.');
        }

        return $nanoseconds;
    }

    /** @param array{id: int, deadline: int} $entry */
    private function siftDown(array $entry): void
    {
        $size = count($this->heap);
        $index = 0;

        while (true) {
            $left = ($index * 2) + 1;
            if ($left >= $size) {
                break;
            }

            $right = $left + 1;
            $child = $left;
            if ($right < $size && $this->less($this->heap[$right], $this->heap[$left])) {
                $child = $right;
            }

            if (!$this->less($this->heap[$child], $entry)) {
                break;
            }

            $this->heap[$index] = $this->heap[$child];
            $index = $child;
        }

        $this->heap[$index] = $entry;
    }
}
