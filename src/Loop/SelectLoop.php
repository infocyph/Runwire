<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Loop;

use Closure;
use Infocyph\Runwire\Loop\Internal\TimerQueue;
use InvalidArgumentException;
use LogicException;
use OverflowException;
use Throwable;

final class SelectLoop implements LoopInterface
{
    private const int MICROS_PER_SECOND = 1_000_000;

    private const int NANOS_PER_SECOND = 1_000_000_000;

    private const int SELECT_ERROR_BACKOFF_MICROS = 1_000;

    private readonly TimerQueue $timers;

    /** @var array<int, Closure> */
    private array $deferred = [];

    private int $nextId = 1;

    /** @var array<int, int> */
    private array $readIndex = [];

    /** @var array<int, array{stream: resource, resource_id: int, callback: Closure}> */
    private array $readWatchers = [];

    private bool $running = false;

    /** @var array<int, int> */
    private array $writeIndex = [];

    /** @var array<int, array{stream: resource, resource_id: int, callback: Closure}> */
    private array $writeWatchers = [];

    public function __construct()
    {
        $this->timers = new TimerQueue();
    }

    public function cancel(int $id): bool
    {
        if ($this->cancelWatcher($id, true) || $this->cancelWatcher($id, false)) {
            return true;
        }

        if ($this->timers->cancel($id)) {
            return true;
        }

        if (!isset($this->deferred[$id])) {
            return false;
        }

        unset($this->deferred[$id]);

        return true;
    }

    public function defer(callable $callback): int
    {
        $id = $this->allocateId();
        $this->deferred[$id] = Closure::fromCallable($callback);

        return $id;
    }

    public function delay(float $seconds, callable $callback): int
    {
        $id = $this->allocateId();
        $this->timers->addDelay($id, $seconds, Closure::fromCallable($callback));

        return $id;
    }

    public function now(): float
    {
        return hrtime(true) / self::NANOS_PER_SECOND;
    }

    public function onReadable(mixed $stream, callable $callback): int
    {
        return $this->watch($stream, $callback, true);
    }

    public function onWritable(mixed $stream, callable $callback): int
    {
        return $this->watch($stream, $callback, false);
    }

    public function repeat(float $interval, callable $callback): int
    {
        $id = $this->allocateId();
        $this->timers->addRepeat($id, $interval, Closure::fromCallable($callback));

        return $id;
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

    private function allocateId(): int
    {
        if ($this->nextId === PHP_INT_MAX) {
            throw new OverflowException('Event loop handle space is exhausted.');
        }

        return $this->nextId++;
    }

    private function cancelWatcher(int $id, bool $readable): bool
    {
        $watcher = $readable
            ? ($this->readWatchers[$id] ?? null)
            : ($this->writeWatchers[$id] ?? null);

        if ($watcher === null) {
            return false;
        }

        if ($readable) {
            unset($this->readWatchers[$id], $this->readIndex[$watcher['resource_id']]);
        } else {
            unset($this->writeWatchers[$id], $this->writeIndex[$watcher['resource_id']]);
        }

        return true;
    }

    /** @param list<resource> $ready */
    private function dispatchReady(array $ready, bool $readable): void
    {
        foreach ($ready as $stream) {
            if (!is_resource($stream)) {
                continue;
            }

            $resourceId = get_resource_id($stream);
            $id = $readable
                ? ($this->readIndex[$resourceId] ?? null)
                : ($this->writeIndex[$resourceId] ?? null);
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

    private function hasReferences(): bool
    {
        return $this->readWatchers !== []
            || $this->writeWatchers !== []
            || $this->timers->hasTimers()
            || $this->deferred !== [];
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
            if ($this->pruneClosedWatchers() === 0) {
                usleep(self::SELECT_ERROR_BACKOFF_MICROS);
            }

            return;
        }

        if ($result > 0) {
            $this->dispatchReady($read, true);
            if ($this->running) {
                $this->dispatchReady($write, false);
            }
        }
    }

    private function pruneClosedDirection(bool $readable): int
    {
        $watchers = $readable ? $this->readWatchers : $this->writeWatchers;
        $removed = 0;

        foreach ($watchers as $id => $watcher) {
            if (!is_resource($watcher['stream'])) {
                $this->cancelWatcher($id, $readable);
                ++$removed;
            }
        }

        return $removed;
    }

    private function pruneClosedWatchers(): int
    {
        $removed = $this->pruneClosedDirection(true);

        return $removed + $this->pruneClosedDirection(false);
    }

    private function runDeferredBatch(): void
    {
        if ($this->deferred === []) {
            return;
        }

        foreach (array_keys($this->deferred) as $id) {
            $callback = $this->takeDeferred($id);
            if ($callback === null) {
                continue;
            }
            $callback($id);

            if (!$this->running) {
                return;
            }
        }
    }

    private function runDueTimers(): void
    {
        foreach ($this->timers->takeDue() as $id) {
            $timer = $this->timers->timer($id);
            if ($timer === null) {
                continue;
            }

            if ($timer['interval'] === 0) {
                $this->timers->consumeOneShot($id);
            }

            try {
                $timer['callback']($id);
            } catch (Throwable $throwable) {
                $this->timers->cancel($id);

                throw $throwable;
            }

            if ($timer['interval'] !== 0) {
                $this->timers->rescheduleRepeat($id);
            }

            if (!$this->running) {
                return;
            }
        }
    }

    /** @return array{0: list<resource>, 1: list<resource>} */
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

    /** @return array{0: ?int, 1: int} */
    private function selectTimeout(): array
    {
        if ($this->deferred !== []) {
            return [0, 0];
        }

        $deadline = $this->timers->nextDeadline();
        if ($deadline === null) {
            return [null, 0];
        }

        $remaining = (int) max(0, $deadline - hrtime(true));

        return [
            intdiv($remaining, self::NANOS_PER_SECOND),
            min(
                intdiv($remaining % self::NANOS_PER_SECOND, 1_000),
                self::MICROS_PER_SECOND - 1,
            ),
        ];
    }

    private function sleepUntilNextTimer(): void
    {
        if ($this->deferred !== []) {
            return;
        }

        $deadline = $this->timers->nextDeadline();
        if ($deadline === null) {
            return;
        }

        $remaining = $deadline - hrtime(true);
        if ($remaining <= 0) {
            return;
        }

        @time_nanosleep(
            intdiv($remaining, self::NANOS_PER_SECOND),
            $remaining % self::NANOS_PER_SECOND,
        );
    }

    private function takeDeferred(int $id): ?Closure
    {
        $callback = $this->deferred[$id] ?? null;
        if ($callback === null) {
            return null;
        }
        unset($this->deferred[$id]);

        return $callback;
    }

    /** @param resource $stream */
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
}
