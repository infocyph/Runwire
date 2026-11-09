<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Tests\Fixtures;

use Closure;
use Infocyph\Runwire\Loop\Internal\SwooleReactorInterface;
use RuntimeException;

final class BatchOSwooleReactor implements SwooleReactorInterface
{
    /** @var list<Closure> */
    private array $deferred = [];

    private int $nextTimerId = 1;

    private bool $resumed = false;

    /** @var array<int, array{callback: Closure, repeat: bool}> */
    private array $timers = [];

    /** @var array<int, array{read: ?Closure, write: ?Closure, flags: int}> */
    private array $watchers = [];

    public function __construct(private readonly int $currentCoroutineId = 17) {}

    #[\Override]
    public function add(mixed $stream, ?Closure $read, ?Closure $write, int $flags): bool
    {
        if (!is_resource($stream)) {
            return false;
        }

        $this->watchers[get_resource_id($stream)] = [
            'read' => $read,
            'write' => $write,
            'flags' => $flags,
        ];

        return true;
    }

    #[\Override]
    public function after(int $milliseconds, Closure $callback): int
    {
        unset($milliseconds);
        $id = $this->nextTimerId++;
        $this->timers[$id] = ['callback' => $callback, 'repeat' => false];

        return $id;
    }

    #[\Override]
    public function clearTimer(int $timerId): bool
    {
        if (!isset($this->timers[$timerId])) {
            return false;
        }

        unset($this->timers[$timerId]);

        return true;
    }

    #[\Override]
    public function coroutineId(): int
    {
        return $this->currentCoroutineId;
    }

    #[\Override]
    public function defer(Closure $callback): void
    {
        $this->deferred[] = $callback;
    }

    #[\Override]
    public function delete(mixed $stream): bool
    {
        if (!is_resource($stream)) {
            return false;
        }

        unset($this->watchers[get_resource_id($stream)]);

        return true;
    }

    #[\Override]
    public function readFlag(): int
    {
        return 1;
    }

    #[\Override]
    public function repeat(int $milliseconds, Closure $callback): int
    {
        unset($milliseconds);
        $id = $this->nextTimerId++;
        $this->timers[$id] = ['callback' => $callback, 'repeat' => true];

        return $id;
    }

    #[\Override]
    public function resumeCoroutine(int $coroutineId): bool
    {
        if ($coroutineId !== $this->currentCoroutineId) {
            return false;
        }

        $this->resumed = true;

        return true;
    }

    #[\Override]
    public function set(mixed $stream, ?Closure $read, ?Closure $write, int $flags): bool
    {
        if (!is_resource($stream)) {
            return false;
        }

        $this->watchers[get_resource_id($stream)] = [
            'read' => $read,
            'write' => $write,
            'flags' => $flags,
        ];

        return true;
    }

    /**
     * @throws \RuntimeException
     */
    #[\Override]
    public function suspendCoroutine(): void
    {
        $this->resumed = false;

        for ($iterations = 0; !$this->wasResumed() && $iterations < 1_000; ++$iterations) {
            if ($this->deferred !== []) {
                $callback = array_shift($this->deferred);
                $callback();

                continue;
            }

            $timerId = array_key_first($this->timers);
            if ($timerId === null) {
                throw new RuntimeException('Fake Swoole reactor has no event capable of resuming the host coroutine.');
            }

            $timer = $this->timers[$timerId];
            if (!$timer['repeat']) {
                unset($this->timers[$timerId]);
            }
            ($timer['callback'])();
        }

        if (!$this->wasResumed()) {
            throw new RuntimeException('Fake Swoole reactor exceeded its bounded dispatch budget.');
        }
    }

    #[\Override]
    public function writeFlag(): int
    {
        return 2;
    }
    private function wasResumed(): bool
    {
        return $this->resumed;
    }
}
