<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Loop;

use Closure;
use Event;
use EventBase;
use Infocyph\Runwire\Internal\MonotonicTime;
use InvalidArgumentException;
use LogicException;
use OverflowException;
use RuntimeException;
use Throwable;

/**
 * Adapts ext-event to Runwire's bounded loop contract without making the extension mandatory.
 */
final class EventLoop implements LoopDiagnosticsProviderInterface, LoopInterface
{
    private readonly EventBase $base;

    private readonly int $callbackOverrunNanoseconds;

    private int $callbackOverrunsTotal = 0;

    /** @var array<int, Event> */
    private array $events = [];

    private int $lastTickNanoseconds = 0;

    private int $maxLagNanoseconds = 0;

    private int $maxTickNanoseconds = 0;

    private int $nextId = 1;

    /** @var array<int, int> */
    private array $readIndex = [];

    private bool $running = false;

    /** @var array<int, int> */
    private array $writeIndex = [];

    /**
     * Create an ext-event loop with the callback-overrun threshold.
     */
    public function __construct(float $callbackOverrunSeconds = 0.05)
    {
        if (!self::supported()) {
            throw new RuntimeException('EventLoop requires the ext-event extension.');
        }
        if (!is_finite($callbackOverrunSeconds) || $callbackOverrunSeconds <= 0 || $callbackOverrunSeconds > 60.0) {
            throw new InvalidArgumentException('Callback overrun threshold must be finite and between 0 and 60 seconds.');
        }

        $this->base = new EventBase();
        $this->callbackOverrunNanoseconds = MonotonicTime::secondsToNanoseconds($callbackOverrunSeconds);
    }

    /**
     * Determine whether the ext-event backend is available.
     */
    public static function supported(): bool
    {
        return extension_loaded('event')
            && class_exists(Event::class, false)
            && class_exists(EventBase::class, false);
    }

    /**
     * Cancel a watcher, timer, or deferred callback handle.
     */
    public function cancel(int $id): bool
    {
        $event = $this->events[$id] ?? null;
        if ($event === null) {
            return false;
        }

        $event->del();
        unset($this->events[$id]);
        $this->removeIndex($id);

        return true;
    }

    /**
     * Queue a callback for the next event-loop turn.
     */
    public function defer(callable $callback): int
    {
        return $this->timer(0.0, $callback, false);
    }

    /**
     * Schedule a one-shot timer.
     */
    public function delay(float $seconds, callable $callback): int
    {
        return $this->timer($seconds, $callback, false);
    }

    /**
     * Capture current event-loop diagnostics.
     */
    public function diagnostics(): LoopDiagnosticsSnapshot
    {
        return new LoopDiagnosticsSnapshot(
            sampledAtMonotonicNanoseconds: MonotonicTime::nowNanoseconds(),
            timersActive: max(0, count($this->events) - count($this->readIndex) - count($this->writeIndex)),
            deferredBacklog: 0,
            readWatchers: count($this->readIndex),
            writeWatchers: count($this->writeIndex),
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
     *
     * @param resource $stream
     */
    public function onReadable(mixed $stream, callable $callback): int
    {
        return $this->watch($stream, $callback, Event::READ, $this->readIndex, 'readable');
    }

    /**
     * Register a writable stream watcher.
     *
     * @param resource $stream
     */
    public function onWritable(mixed $stream, callable $callback): int
    {
        return $this->watch($stream, $callback, Event::WRITE, $this->writeIndex, 'writable');
    }

    /**
     * Schedule a repeating timer.
     */
    public function repeat(float $interval, callable $callback): int
    {
        if (!is_finite($interval) || $interval <= 0) {
            throw new InvalidArgumentException('Repeating timer interval must be finite and positive.');
        }

        return $this->timer($interval, $callback, true);
    }

    /**
     * Run until stopped or no referenced event remains.
     */
    public function run(): void
    {
        if ($this->running) {
            throw new LogicException('The event loop is already running.');
        }

        $this->running = true;

        try {
            $this->base->loop();
        } finally {
            $this->running = false;
        }
    }

    /**
     * Request that the running loop stop.
     */
    public function stop(): void
    {
        $this->base->stop();
        $this->running = false;
    }

    private function allocateId(): int
    {
        if ($this->nextId === PHP_INT_MAX) {
            throw new OverflowException('Event loop handle space is exhausted.');
        }

        return $this->nextId++;
    }

    private function invoke(Closure $callback, mixed ...$arguments): void
    {
        $started = MonotonicTime::nowNanoseconds();

        try {
            $callback(...$arguments);
        } finally {
            $elapsed = MonotonicTime::nowNanoseconds() - $started;
            $this->lastTickNanoseconds = $elapsed;
            $this->maxTickNanoseconds = max($this->maxTickNanoseconds, $elapsed);
            if ($elapsed >= $this->callbackOverrunNanoseconds) {
                ++$this->callbackOverrunsTotal;
            }
        }
    }

    private function removeIndex(int $id): void
    {
        foreach ($this->readIndex as $resourceId => $watcherId) {
            if ($watcherId === $id) {
                unset($this->readIndex[$resourceId]);
            }
        }
        foreach ($this->writeIndex as $resourceId => $watcherId) {
            if ($watcherId === $id) {
                unset($this->writeIndex[$resourceId]);
            }
        }
    }

    private function timer(float $seconds, callable $callback, bool $repeat): int
    {
        if (!is_finite($seconds) || $seconds < 0) {
            throw new InvalidArgumentException('Timer delay must be finite and non-negative.');
        }

        $id = $this->allocateId();
        $closure = Closure::fromCallable($callback);
        $deadline = MonotonicTime::nowNanoseconds() + MonotonicTime::secondsToNanoseconds($seconds);
        $event = Event::timer(
            $this->base,
            function () use ($id, $closure, $repeat, $seconds, &$deadline): void {
                if (!isset($this->events[$id])) {
                    return;
                }

                $now = MonotonicTime::nowNanoseconds();
                $this->maxLagNanoseconds = max($this->maxLagNanoseconds, max(0, $now - $deadline));
                if (!$repeat) {
                    unset($this->events[$id]);
                }

                try {
                    $this->invoke($closure, $id);
                } catch (Throwable $error) {
                    $this->cancel($id);

                    throw $error;
                }

                $event = $this->events[$id] ?? null;
                if ($repeat && $event !== null) {
                    $deadline = MonotonicTime::nowNanoseconds() + MonotonicTime::secondsToNanoseconds($seconds);
                    $event->add($seconds);
                }
            },
        );
        if (!$event->add($seconds)) {
            throw new RuntimeException('Unable to register event-loop timer.');
        }
        $this->events[$id] = $event;

        return $id;
    }

    /** @param array<int, int> $index */
    private function watch(mixed $stream, callable $callback, int $flag, array &$index, string $direction): int
    {
        if (!is_resource($stream) || get_resource_type($stream) !== 'stream') {
            throw new InvalidArgumentException('A live stream resource is required.');
        }

        $resourceId = get_resource_id($stream);
        if (isset($index[$resourceId])) {
            throw new InvalidArgumentException(sprintf('Stream %d already has a %s watcher.', $resourceId, $direction));
        }

        $id = $this->allocateId();
        $closure = Closure::fromCallable($callback);
        $event = new Event(
            $this->base,
            $stream,
            $flag | Event::PERSIST,
            function (mixed $ready) use ($id, $stream, $closure): void {
                if (!isset($this->events[$id]) || !is_resource($stream)) {
                    return;
                }
                $this->invoke($closure, $ready, $id);
            },
        );
        if (!$event->add()) {
            throw new RuntimeException('Unable to register event-loop stream watcher.');
        }

        $this->events[$id] = $event;
        $index[$resourceId] = $id;

        return $id;
    }
}
