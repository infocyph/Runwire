<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Loop;

use Closure;
use Infocyph\Runwire\Loop\Internal\NativeSwooleReactor;
use Infocyph\Runwire\Loop\Internal\SwooleReactorInterface;
use InvalidArgumentException;
use LogicException;
use OverflowException;
use RuntimeException;
use Throwable;

final class SwooleLoop implements LoopDiagnosticsProviderInterface, LoopInterface
{
    private const int MILLISECONDS_PER_SECOND = 1_000;

    private const int NANOS_PER_SECOND = 1_000_000_000;

    private readonly SwooleReactorInterface $reactor;

    /** @var array<int, Closure> */
    private array $deferred = [];

    private bool $idleCheckScheduled = false;

    private int $nextId = 1;

    /** @var array<int, int> */
    private array $readIndex = [];

    /** @var array<int, array{stream: resource, resource_id: int, callback: Closure}> */
    private array $readWatchers = [];

    /** @var array<int, resource> */
    private array $registeredStreams = [];

    private bool $running = false;

    private bool $stopRequested = false;

    /** @var array<int, int> */
    private array $timers = [];

    private ?int $waitingCoroutineId = null;

    /** @var array<int, int> */
    private array $writeIndex = [];

    /** @var array<int, array{stream: resource, resource_id: int, callback: Closure}> */
    private array $writeWatchers = [];

    /** @internal The reactor argument exists for deterministic adapter tests. */
    public function __construct(?SwooleReactorInterface $reactor = null)
    {
        $this->reactor = $reactor ?? new NativeSwooleReactor();
    }

    public function cancel(int $id): bool
    {
        if (isset($this->deferred[$id])) {
            unset($this->deferred[$id]);
            $this->scheduleIdleCheck();

            return true;
        }
        if (isset($this->timers[$id])) {
            return $this->cancelTimer($id);
        }

        return $this->cancelWatcher($id);
    }

    public function defer(callable $callback): int
    {
        $id = $this->allocateId();
        $this->deferred[$id] = Closure::fromCallable($callback);
        $this->reactor->defer(function () use ($id): void {
            $callback = $this->deferred[$id] ?? null;
            if ($callback === null) {
                return;
            }

            unset($this->deferred[$id]);

            try {
                $callback($id);
            } finally {
                $this->scheduleIdleCheck();
            }
        });

        return $id;
    }

    public function delay(float $seconds, callable $callback): int
    {
        $milliseconds = self::milliseconds($seconds, true);
        if ($milliseconds === 0) {
            return $this->defer($callback);
        }

        $id = $this->allocateId();
        $closure = Closure::fromCallable($callback);
        $nativeId = $this->reactor->after($milliseconds, function () use ($id, $closure): void {
            if (!isset($this->timers[$id])) {
                return;
            }

            unset($this->timers[$id]);

            try {
                $closure($id);
            } finally {
                $this->scheduleIdleCheck();
            }
        });
        $this->timers[$id] = $nativeId;

        return $id;
    }

    public function diagnostics(): LoopDiagnosticsSnapshot
    {
        $now = hrtime(true);

        return new LoopDiagnosticsSnapshot(
            sampledAtMonotonicNanoseconds: is_int($now) ? $now : (int) $now,
            timersActive: count($this->timers),
            deferredBacklog: count($this->deferred),
            readWatchers: count($this->readWatchers),
            writeWatchers: count($this->writeWatchers),
        );
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
        $milliseconds = self::milliseconds($interval, false);
        $id = $this->allocateId();
        $closure = Closure::fromCallable($callback);
        $nativeId = $this->reactor->repeat($milliseconds, function () use ($id, $closure): void {
            if (!isset($this->timers[$id])) {
                return;
            }

            try {
                $closure($id);
            } catch (Throwable $error) {
                $this->cancelTimer($id);

                throw $error;
            }
        });
        $this->timers[$id] = $nativeId;

        return $id;
    }

    public function run(): void
    {
        if ($this->running) {
            throw new LogicException('The Swoole/OpenSwoole loop bridge is already running.');
        }
        if (!$this->hasReferences()) {
            return;
        }

        $coroutineId = $this->reactor->coroutineId();
        if ($coroutineId < 0) {
            throw new LogicException(
                'SwooleLoop::run() requires an active Swoole/OpenSwoole host coroutine; nested host event loops are not supported.',
            );
        }

        $this->running = true;
        $this->stopRequested = false;
        $this->waitingCoroutineId = $coroutineId;
        $this->scheduleIdleCheck();

        try {
            $this->reactor->suspendCoroutine();
            if (!$this->stopRequested && $this->hasReferences()) {
                throw new RuntimeException('The Swoole/OpenSwoole host coroutine resumed before the Runwire loop became idle.');
            }
        } finally {
            $this->running = false;
            $this->stopRequested = false;
            $this->waitingCoroutineId = null;
        }
    }

    public function stop(): void
    {
        if (!$this->running) {
            return;
        }

        $this->stopRequested = true;
        $this->scheduleIdleCheck();
    }

    private static function milliseconds(float $seconds, bool $allowZero): int
    {
        if (!is_finite($seconds) || $seconds < 0.0 || (!$allowZero && $seconds <= 0.0)) {
            throw new InvalidArgumentException(
                $allowZero
                    ? 'Timer delay must be a finite non-negative number.'
                    : 'Repeating timer interval must be a finite positive number.',
            );
        }
        if ($seconds === 0.0) {
            return 0;
        }
        if ($seconds > PHP_INT_MAX / self::MILLISECONDS_PER_SECOND) {
            throw new OverflowException('Timer duration exceeds the platform integer range.');
        }

        return max(1, (int) ceil($seconds * self::MILLISECONDS_PER_SECOND));
    }

    private function allocateId(): int
    {
        if ($this->nextId === PHP_INT_MAX) {
            throw new OverflowException('Event loop handle space is exhausted.');
        }

        return $this->nextId++;
    }

    private function cancelTimer(int $id): bool
    {
        $nativeId = $this->timers[$id] ?? null;
        if ($nativeId === null) {
            return false;
        }
        if (!$this->reactor->clearTimer($nativeId)) {
            throw new RuntimeException('Swoole/OpenSwoole failed to clear an active Runwire timer.');
        }

        unset($this->timers[$id]);
        $this->scheduleIdleCheck();

        return true;
    }

    private function cancelWatcher(int $id): bool
    {
        $readable = isset($this->readWatchers[$id]);
        $watcher = $readable ? $this->readWatchers[$id] : ($this->writeWatchers[$id] ?? null);
        if ($watcher === null) {
            return false;
        }

        $resourceId = $watcher['resource_id'];
        if ($readable) {
            unset($this->readWatchers[$id], $this->readIndex[$resourceId]);
        } else {
            unset($this->writeWatchers[$id], $this->writeIndex[$resourceId]);
        }

        try {
            $this->syncWatcher($watcher['stream'], $resourceId);
        } catch (Throwable $error) {
            if ($readable) {
                $this->readWatchers[$id] = $watcher;
                $this->readIndex[$resourceId] = $id;
            } else {
                $this->writeWatchers[$id] = $watcher;
                $this->writeIndex[$resourceId] = $id;
            }

            throw $error;
        }

        $this->scheduleIdleCheck();

        return true;
    }

    private function dispatchAndReturn(int $resourceId, bool $readable): null
    {
        $this->dispatchWatcher($resourceId, $readable);

        return null;
    }

    private function dispatchWatcher(int $resourceId, bool $readable): void
    {
        $id = $readable ? ($this->readIndex[$resourceId] ?? null) : ($this->writeIndex[$resourceId] ?? null);
        if ($id === null) {
            return;
        }

        $watcher = $readable ? ($this->readWatchers[$id] ?? null) : ($this->writeWatchers[$id] ?? null);
        if ($watcher === null || !is_resource($watcher['stream'])) {
            return;
        }

        ($watcher['callback'])($watcher['stream'], $id);
    }

    private function hasReferences(): bool
    {
        return $this->deferred !== []
            || $this->timers !== []
            || $this->readWatchers !== []
            || $this->writeWatchers !== [];
    }

    /** @param resource $stream */
    private function removeRegisteredWatcher(mixed $stream, int $resourceId): void
    {
        if (isset($this->registeredStreams[$resourceId]) && !$this->reactor->delete($stream)) {
            throw new RuntimeException('Swoole/OpenSwoole failed to remove a Runwire stream watcher.');
        }
        unset($this->registeredStreams[$resourceId]);
    }

    private function scheduleIdleCheck(): void
    {
        if (!$this->running || $this->idleCheckScheduled) {
            return;
        }

        $this->idleCheckScheduled = true;
        $this->reactor->defer(function (): void {
            $this->idleCheckScheduled = false;
            if (!$this->running || $this->waitingCoroutineId === null) {
                return;
            }
            if (!$this->stopRequested && $this->hasReferences()) {
                return;
            }
            if (!$this->reactor->resumeCoroutine($this->waitingCoroutineId)) {
                throw new RuntimeException('Swoole/OpenSwoole failed to resume the Runwire host coroutine.');
            }
        });
    }

    /** @param resource $stream */
    private function syncWatcher(mixed $stream, int $resourceId): void
    {
        $readable = isset($this->readIndex[$resourceId]);
        $writable = isset($this->writeIndex[$resourceId]);
        if (!$readable && !$writable) {
            $this->removeRegisteredWatcher($stream, $resourceId);

            return;
        }

        $flags = ($readable ? $this->reactor->readFlag() : 0)
            | ($writable ? $this->reactor->writeFlag() : 0);
        $read = $readable ? fn(): null => $this->dispatchAndReturn($resourceId, true) : null;
        $write = $writable ? fn(): null => $this->dispatchAndReturn($resourceId, false) : null;

        if (isset($this->registeredStreams[$resourceId])) {
            if (!$this->reactor->set($stream, $read, $write, $flags)) {
                throw new RuntimeException('Swoole/OpenSwoole failed to update a Runwire stream watcher.');
            }

            return;
        }
        if (!$this->reactor->add($stream, $read, $write, $flags)) {
            throw new RuntimeException('Swoole/OpenSwoole failed to register a Runwire stream watcher.');
        }
        $this->registeredStreams[$resourceId] = $stream;
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

        try {
            $this->syncWatcher($stream, $resourceId);
        } catch (Throwable $error) {
            if ($readable) {
                unset($this->readWatchers[$id], $this->readIndex[$resourceId]);
            } else {
                unset($this->writeWatchers[$id], $this->writeIndex[$resourceId]);
            }

            throw $error;
        }

        return $id;
    }
}
