<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Loop;

use Closure;
use Infocyph\Runwire\Internal\MonotonicTime;
use Infocyph\Runwire\Loop\Internal\TimerQueue;
use InvalidArgumentException;
use LogicException;
use OverflowException;
use Throwable;

/**
 * Implements a portable stream-select event loop with timers and diagnostics.
 */
final class SelectLoop implements LoopDiagnosticsProviderInterface, LoopInterface
{
    private const int MICROS_PER_SECOND = 1_000_000;

    private const int SELECT_ERROR_BACKOFF_MICROS = 1_000;

    private readonly int $callbackOverrunNanoseconds;

    private readonly TimerQueue $timers;

    private int $callbackOverrunsTotal = 0;

    /** @var array<int, Closure> */
    private array $deferred = [];

    private int $lastTickNanoseconds = 0;

    private int $maxLagNanoseconds = 0;

    private int $maxTickNanoseconds = 0;

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

    /**
     * Create a select-loop with the callback-overrun threshold.
     */
    public function __construct(float $callbackOverrunSeconds = 0.05)
    {
        if (!is_finite($callbackOverrunSeconds) || $callbackOverrunSeconds <= 0 || $callbackOverrunSeconds > 60.0) {
            throw new InvalidArgumentException('Callback overrun threshold must be finite and between 0 and 60 seconds.');
        }

        $this->callbackOverrunNanoseconds = MonotonicTime::secondsToNanoseconds($callbackOverrunSeconds);
        $this->timers = new TimerQueue();
    }

    /**
     * Cancel a watcher, timer, or deferred callback handle.
     */
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

    /**
     * Queue a callback for the next loop turn.
     */
    public function defer(callable $callback): int
    {
        $id = $this->allocateId();
        $this->deferred[$id] = Closure::fromCallable($callback);

        return $id;
    }

    /**
     * Schedule a one-shot timer.
     */
    public function delay(float $seconds, callable $callback): int
    {
        $id = $this->allocateId();
        $this->timers->addDelay($id, $seconds, Closure::fromCallable($callback));

        return $id;
    }

    /**
     * Capture current loop diagnostics.
     */
    public function diagnostics(): LoopDiagnosticsSnapshot
    {
        return new LoopDiagnosticsSnapshot(
            sampledAtMonotonicNanoseconds: MonotonicTime::nowNanoseconds(),
            timersActive: $this->timers->count(),
            deferredBacklog: count($this->deferred),
            readWatchers: count($this->readWatchers),
            writeWatchers: count($this->writeWatchers),
            lastTickNanoseconds: $this->lastTickNanoseconds,
            maxTickNanoseconds: $this->maxTickNanoseconds,
            maxLagNanoseconds: $this->maxLagNanoseconds,
            callbackOverrunsTotal: $this->callbackOverrunsTotal,
        );
    }

    /**
     * Return monotonic time in seconds.
     */
    public function now(): float
    {
        return MonotonicTime::nowNanoseconds() / MonotonicTime::NANOSECONDS_PER_SECOND;
    }

    /**
     * Register a readable stream watcher.
     */
    public function onReadable(mixed $stream, callable $callback): int
    {
        return $this->watch($stream, $callback, true);
    }

    /**
     * Register a writable stream watcher.
     */
    public function onWritable(mixed $stream, callable $callback): int
    {
        return $this->watch($stream, $callback, false);
    }

    /**
     * Schedule a repeating timer.
     */
    public function repeat(float $interval, callable $callback): int
    {
        $id = $this->allocateId();
        $this->timers->addRepeat($id, $interval, Closure::fromCallable($callback));

        return $id;
    }

    /**
     * Run the event loop until stopped or idle.
     */
    public function run(): void
    {
        if ($this->running) {
            throw new LogicException('The event loop is already running.');
        }

        $this->running = true;

        try {
            while ($this->running) {
                $this->runTick();

                if (!$this->running || !$this->hasReferences()) {
                    break;
                }

                $this->poll();
            }
        } finally {
            $this->running = false;
        }
    }

    /**
     * Stop a running event loop.
     */
    public function stop(): void
    {
        $this->running = false;
    }

    /**
     * Execute one non-polling loop tick.
     */
    public function tick(): void
    {
        if ($this->running) {
            throw new LogicException('The event loop cannot be ticked while it is running.');
        }

        $this->running = true;

        try {
            $this->runTick();
        } finally {
            $this->running = false;
        }
    }

    private static function ignoreInterruptedSelectWarning(int $severity, string $message): bool
    {
        return $severity === E_WARNING
            && str_starts_with($message, 'stream_select():')
            && str_contains($message, 'Interrupted system call');
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

            $this->invoke($watcher['callback'], $stream, $id);
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

    private function invoke(Closure $callback, mixed ...$arguments): void
    {
        $started = MonotonicTime::nowNanoseconds();

        try {
            $callback(...$arguments);
        } finally {
            if (MonotonicTime::nowNanoseconds() - $started >= $this->callbackOverrunNanoseconds) {
                ++$this->callbackOverrunsTotal;
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
        set_error_handler(self::ignoreInterruptedSelectWarning(...));

        try {
            $result = stream_select($read, $write, $except, $seconds, $microseconds);
        } finally {
            restore_error_handler();
        }

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

    private function recordTick(int $tickStart): void
    {
        $this->lastTickNanoseconds = max(0, MonotonicTime::nowNanoseconds() - $tickStart);
        $this->maxTickNanoseconds = max($this->maxTickNanoseconds, $this->lastTickNanoseconds);
    }

    private function recordTimerLag(int $now): void
    {
        $deadline = $this->timers->nextDeadline();
        if ($deadline !== null && $deadline < $now) {
            $this->maxLagNanoseconds = max($this->maxLagNanoseconds, $now - $deadline);
        }
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
            $this->invoke($callback, $id);

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
                $this->invoke($timer['callback'], $id);
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

    private function runTick(): void
    {
        $tickStart = MonotonicTime::nowNanoseconds();
        $this->recordTimerLag($tickStart);
        $this->runDeferredBatch();
        if ($this->running) {
            $this->runDueTimers();
        }
        $this->recordTick($tickStart);
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

        $remaining = max(0, $deadline - MonotonicTime::nowNanoseconds());

        return [
            intdiv($remaining, MonotonicTime::NANOSECONDS_PER_SECOND),
            min(
                intdiv($remaining % MonotonicTime::NANOSECONDS_PER_SECOND, 1_000),
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

        $remaining = $deadline - MonotonicTime::nowNanoseconds();
        if ($remaining <= 0) {
            return;
        }

        time_nanosleep(
            intdiv($remaining, MonotonicTime::NANOSECONDS_PER_SECOND),
            $remaining % MonotonicTime::NANOSECONDS_PER_SECOND,
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
