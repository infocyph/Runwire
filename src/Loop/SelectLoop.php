<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Loop;

use Closure;
use InvalidArgumentException;
use LogicException;
use OverflowException;
use Throwable;

final class SelectLoop implements LoopInterface
{
    private const int NANOS_PER_SECOND = 1_000_000_000;
    private const int MICROS_PER_SECOND = 1_000_000;
    private const int TIMER_COMPACTION_FLOOR = 64;
    private const int SELECT_ERROR_BACKOFF_MICROS = 1_000;

    private int $nextId = 1;
    private bool $running = false;

    /** @var array<int, array{stream: resource, resource_id: int, callback: Closure}> */
    private array $readWatchers = [];

    /** @var array<int, array{stream: resource, resource_id: int, callback: Closure}> */
    private array $writeWatchers = [];

    /** @var array<int, int> */
    private array $readIndex = [];

    /** @var array<int, int> */
    private array $writeIndex = [];

    /** @var array<int, array{deadline: int, interval: int, callback: Closure}> */
    private array $timers = [];

    /** @var list<array{id: int, deadline: int}> */
    private array $timerHeap = [];

    private int $cancelledTimerEntries = 0;

    /** @var array<int, Closure> */
    private array $deferred = [];

    public function onReadable(mixed $stream, callable $callback): int
    {
        return $this->watch($stream, $callback, true);
    }

    public function onWritable(mixed $stream, callable $callback): int
    {
        return $this->watch($stream, $callback, false);
    }

    public function delay(float $seconds, callable $callback): int
    {
        return $this->scheduleTimer($seconds, 0, $callback);
    }

    public function repeat(float $interval, callable $callback): int
    {
        $intervalNs = $this->secondsToNanoseconds($interval, false);

        return $this->scheduleTimer($interval, $intervalNs, $callback);
    }

    public function defer(callable $callback): int
    {
        $id = $this->allocateId();
        $this->deferred[$id] = Closure::fromCallable($callback);

        return $id;
    }

    public function cancel(int $id): bool
    {
        if (isset($this->readWatchers[$id])) {
            $resourceId = $this->readWatchers[$id]['resource_id'];
            unset($this->readWatchers[$id], $this->readIndex[$resourceId]);

            return true;
        }

        if (isset($this->writeWatchers[$id])) {
            $resourceId = $this->writeWatchers[$id]['resource_id'];
            unset($this->writeWatchers[$id], $this->writeIndex[$resourceId]);

            return true;
        }

        if (isset($this->timers[$id])) {
            unset($this->timers[$id]);
            ++$this->cancelledTimerEntries;
            $this->compactTimerHeapIfNeeded();

            return true;
        }

        if (isset($this->deferred[$id])) {
            unset($this->deferred[$id]);

            return true;
        }

        return false;
    }

    public function run(): void
    {
        if ($this->running) {
            throw new LogicException('The event loop is already running.');
        }

        $this->running = true;

        try {
            while ($this->running) {
                $this->runDeferredBatch();
                $this->runDueTimers();

                if (!$this->running || !$this->hasReferences()) {
                    break;
                }

                $this->poll();
            }
        } finally {
            $this->running = false;
        }
    }

    public function stop(): void
    {
        $this->running = false;
    }

    public function now(): float
    {
        return hrtime(true) / self::NANOS_PER_SECOND;
    }

    /**
     * @param resource $stream
     */
    private function watch(mixed $stream, callable $callback, bool $readable): int
    {
        if (!is_resource($stream) || get_resource_type($stream) !== 'stream') {
            throw new InvalidArgumentException('A live stream resource is required.');
        }

        $resourceId = get_resource_id($stream);
        $index = $readable ? $this->readIndex : $this->writeIndex;

        if (isset($index[$resourceId])) {
            throw new InvalidArgumentException(sprintf(
                'Stream %d already has a %s watcher.',
                $resourceId,
                $readable ? 'readable' : 'writable',
            ));
        }

        $id = $this->allocateId();
        $watcher = [
            'stream' => $stream,
            'resource_id' => $resourceId,
            'callback' => Closure::fromCallable($callback),
        ];

        if ($readable) {
            $this->readWatchers[$id] = $watcher;
            $this->readIndex[$resourceId] = $id;
        } else {
            $this->writeWatchers[$id] = $watcher;
            $this->writeIndex[$resourceId] = $id;
        }

        return $id;
    }

    private function scheduleTimer(float $seconds, int $intervalNs, callable $callback): int
    {
        $delayNs = $this->secondsToNanoseconds($seconds, true);
        $now = hrtime(true);

        if ($delayNs > PHP_INT_MAX - $now) {
            throw new OverflowException('Timer deadline exceeds the platform integer range.');
        }

        $id = $this->allocateId();
        $deadline = $now + $delayNs;

        $this->timers[$id] = [
            'deadline' => $deadline,
            'interval' => $intervalNs,
            'callback' => Closure::fromCallable($callback),
        ];
        $this->heapPush($id, $deadline);

        return $id;
    }

    private function runDeferredBatch(): void
    {
        if ($this->deferred === []) {
            return;
        }

        $ids = array_keys($this->deferred);

        foreach ($ids as $id) {
            if (!isset($this->deferred[$id])) {
                continue;
            }

            $callback = $this->deferred[$id];
            unset($this->deferred[$id]);
            $callback($id);

            if (!$this->running) {
                return;
            }
        }
    }

    private function runDueTimers(): void
    {
        if ($this->timerHeap === []) {
            return;
        }

        $now = hrtime(true);
        $due = [];

        while (($next = $this->heapPeek()) !== null && $next['deadline'] <= $now) {
            $entry = $this->heapPop();
            if ($entry === null) {
                break;
            }

            $id = $entry['id'];
            $timer = $this->timers[$id] ?? null;

            if ($timer === null || $timer['deadline'] !== $entry['deadline']) {
                if ($timer === null && $this->cancelledTimerEntries > 0) {
                    --$this->cancelledTimerEntries;
                }

                continue;
            }

            $due[] = $id;
        }

        foreach ($due as $id) {
            $timer = $this->timers[$id] ?? null;
            if ($timer === null) {
                continue;
            }

            if ($timer['interval'] === 0) {
                unset($this->timers[$id]);
                $timer['callback']($id);
            } else {
                try {
                    $timer['callback']($id);
                } catch (Throwable $throwable) {
                    unset($this->timers[$id]);
                    throw $throwable;
                }

                if (isset($this->timers[$id])) {
                    $deadline = $this->nextRepeatDeadline(
                        previousDeadline: $timer['deadline'],
                        interval: $timer['interval'],
                        now: hrtime(true),
                    );
                    $this->timers[$id]['deadline'] = $deadline;
                    $this->heapPush($id, $deadline);
                }
            }

            if (!$this->running) {
                return;
            }
        }
    }

    private function poll(): void
    {
        [$read, $write] = $this->selectStreams();

        if ($read === [] && $write === []) {
            $this->sleepUntilNextTimer();

            return;
        }

        [$seconds, $microseconds] = $this->selectTimeout();
        $except = null;
        $result = @stream_select($read, $write, $except, $seconds, $microseconds);

        if ($result === false) {
            $removed = $this->pruneClosedWatchers();
            if ($removed === 0) {
                usleep(self::SELECT_ERROR_BACKOFF_MICROS);
            }

            return;
        }

        if ($result === 0) {
            return;
        }

        $this->dispatchReady($read, true);

        if ($this->running) {
            $this->dispatchReady($write, false);
        }
    }

    /**
     * @return array{0: list<resource>, 1: list<resource>}
     */
    private function selectStreams(): array
    {
        $this->pruneClosedWatchers();

        $read = [];
        foreach ($this->readWatchers as $watcher) {
            $read[] = $watcher['stream'];
        }

        $write = [];
        foreach ($this->writeWatchers as $watcher) {
            $write[] = $watcher['stream'];
        }

        return [$read, $write];
    }

    /**
     * @param list<resource> $ready
     */
    private function dispatchReady(array $ready, bool $readable): void
    {
        foreach ($ready as $stream) {
            if (!is_resource($stream)) {
                continue;
            }

            $resourceId = get_resource_id($stream);
            $index = $readable ? $this->readIndex : $this->writeIndex;
            $id = $index[$resourceId] ?? null;

            if ($id === null) {
                continue;
            }

            $watcher = $readable
                ? ($this->readWatchers[$id] ?? null)
                : ($this->writeWatchers[$id] ?? null);

            if ($watcher === null) {
                continue;
            }

            $watcher['callback']($stream, $id);

            if (!$this->running) {
                return;
            }
        }
    }

    private function pruneClosedWatchers(): int
    {
        $removed = 0;

        foreach ($this->readWatchers as $id => $watcher) {
            if (!is_resource($watcher['stream'])) {
                unset($this->readWatchers[$id], $this->readIndex[$watcher['resource_id']]);
                ++$removed;
            }
        }

        foreach ($this->writeWatchers as $id => $watcher) {
            if (!is_resource($watcher['stream'])) {
                unset($this->writeWatchers[$id], $this->writeIndex[$watcher['resource_id']]);
                ++$removed;
            }
        }

        return $removed;
    }

    /**
     * @return array{0: ?int, 1: int}
     */
    private function selectTimeout(): array
    {
        if ($this->deferred !== []) {
            return [0, 0];
        }

        $deadline = $this->nextTimerDeadline();
        if ($deadline === null) {
            return [null, 0];
        }

        $remaining = max(0, $deadline - hrtime(true));
        $seconds = intdiv($remaining, self::NANOS_PER_SECOND);
        $microseconds = intdiv($remaining % self::NANOS_PER_SECOND, 1_000);

        return [$seconds, min($microseconds, self::MICROS_PER_SECOND - 1)];
    }

    private function sleepUntilNextTimer(): void
    {
        if ($this->deferred !== []) {
            return;
        }

        $deadline = $this->nextTimerDeadline();
        if ($deadline === null) {
            return;
        }

        $remaining = $deadline - hrtime(true);
        if ($remaining <= 0) {
            return;
        }

        $seconds = intdiv($remaining, self::NANOS_PER_SECOND);
        $nanoseconds = $remaining % self::NANOS_PER_SECOND;
        @time_nanosleep($seconds, $nanoseconds);
    }

    private function nextTimerDeadline(): ?int
    {
        while (($entry = $this->heapPeek()) !== null) {
            $timer = $this->timers[$entry['id']] ?? null;

            if ($timer !== null && $timer['deadline'] === $entry['deadline']) {
                return $entry['deadline'];
            }

            $this->heapPop();
            if ($timer === null && $this->cancelledTimerEntries > 0) {
                --$this->cancelledTimerEntries;
            }
        }

        return null;
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

    private function hasReferences(): bool
    {
        return $this->readWatchers !== []
            || $this->writeWatchers !== []
            || $this->timers !== []
            || $this->deferred !== [];
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

    private function allocateId(): int
    {
        if ($this->nextId === PHP_INT_MAX) {
            throw new OverflowException('Event loop handle space is exhausted.');
        }

        return $this->nextId++;
    }

    private function compactTimerHeapIfNeeded(): void
    {
        $heapSize = count($this->timerHeap);

        if ($this->cancelledTimerEntries < self::TIMER_COMPACTION_FLOOR
            || $this->cancelledTimerEntries * 2 < $heapSize) {
            return;
        }

        $this->timerHeap = [];
        foreach ($this->timers as $id => $timer) {
            $this->heapPush($id, $timer['deadline']);
        }

        $this->cancelledTimerEntries = 0;
    }

    private function heapPush(int $id, int $deadline): void
    {
        $entry = ['id' => $id, 'deadline' => $deadline];
        $index = count($this->timerHeap);

        while ($index > 0) {
            $parent = intdiv($index - 1, 2);
            if (!$this->heapLess($entry, $this->timerHeap[$parent])) {
                break;
            }

            $this->timerHeap[$index] = $this->timerHeap[$parent];
            $index = $parent;
        }

        $this->timerHeap[$index] = $entry;
    }

    /**
     * @return array{id: int, deadline: int}|null
     */
    private function heapPeek(): ?array
    {
        return $this->timerHeap[0] ?? null;
    }

    /**
     * @return array{id: int, deadline: int}|null
     */
    private function heapPop(): ?array
    {
        if ($this->timerHeap === []) {
            return null;
        }

        $root = $this->timerHeap[0];
        $last = array_pop($this->timerHeap);

        if ($this->timerHeap === []) {
            return $root;
        }

        $size = count($this->timerHeap);
        $index = 0;

        while (true) {
            $left = ($index * 2) + 1;
            if ($left >= $size) {
                break;
            }

            $right = $left + 1;
            $child = $left;

            if ($right < $size && $this->heapLess($this->timerHeap[$right], $this->timerHeap[$left])) {
                $child = $right;
            }

            if (!$this->heapLess($this->timerHeap[$child], $last)) {
                break;
            }

            $this->timerHeap[$index] = $this->timerHeap[$child];
            $index = $child;
        }

        $this->timerHeap[$index] = $last;

        return $root;
    }

    /**
     * @param array{id: int, deadline: int} $left
     * @param array{id: int, deadline: int} $right
     */
    private function heapLess(array $left, array $right): bool
    {
        return $left['deadline'] < $right['deadline']
            || ($left['deadline'] === $right['deadline'] && $left['id'] < $right['id']);
    }
}
