<?php

declare(strict_types=1);

use Infocyph\Runwire\Coroutine\CoroutineRuntime;
use Infocyph\Runwire\Coroutine\CoroutineScope;
use Infocyph\Runwire\Loop\Internal\SwooleReactorInterface;
use Infocyph\Runwire\Loop\SwooleLoop;

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

    public function after(int $milliseconds, Closure $callback): int
    {
        unset($milliseconds);
        $id = $this->nextTimerId++;
        $this->timers[$id] = ['callback' => $callback, 'repeat' => false];

        return $id;
    }

    public function clearTimer(int $timerId): bool
    {
        if (!isset($this->timers[$timerId])) {
            return false;
        }

        unset($this->timers[$timerId]);

        return true;
    }

    public function coroutineId(): int
    {
        return $this->currentCoroutineId;
    }

    public function defer(Closure $callback): void
    {
        $this->deferred[] = $callback;
    }

    public function delete(mixed $stream): bool
    {
        if (!is_resource($stream)) {
            return false;
        }

        unset($this->watchers[get_resource_id($stream)]);

        return true;
    }

    public function readFlag(): int
    {
        return 1;
    }

    public function repeat(int $milliseconds, Closure $callback): int
    {
        unset($milliseconds);
        $id = $this->nextTimerId++;
        $this->timers[$id] = ['callback' => $callback, 'repeat' => true];

        return $id;
    }

    public function resumeCoroutine(int $coroutineId): bool
    {
        if ($coroutineId !== $this->currentCoroutineId) {
            return false;
        }

        $this->resumed = true;

        return true;
    }

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

    public function suspendCoroutine(): void
    {
        $this->resumed = false;

        for ($iterations = 0; !$this->resumed && $iterations < 1_000; ++$iterations) {
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

        if (!$this->resumed) {
            throw new RuntimeException('Fake Swoole reactor exceeded its bounded dispatch budget.');
        }
    }

    public function writeFlag(): int
    {
        return 2;
    }
}

it('drives Runwire Fibers through a host-owned Swoole reactor bridge', function (): void {
    $reactor = new BatchOSwooleReactor();
    $loop = new SwooleLoop($reactor);
    $runtime = new CoroutineRuntime($loop);
    $events = [];

    $result = $runtime->run(function (CoroutineScope $scope) use (&$events): int {
        $task = $scope->spawn(function () use ($scope, &$events): int {
            $events[] = 'child:start';
            $scope->yieldNow();
            $events[] = 'child:end';

            return 42;
        });
        $scope->sleep(0.001);
        $events[] = 'root:awake';

        return $task->await();
    });

    expect($result)->toBe(42)
        ->and($events)->toBe(['child:start', 'child:end', 'root:awake'])
        ->and($loop->diagnostics()->timersActive)->toBe(0)
        ->and($loop->diagnostics()->deferredBacklog)->toBe(0);
});

it('refuses to drive the Swoole bridge without an active host coroutine', function (): void {
    $loop = new SwooleLoop(new BatchOSwooleReactor(-1));
    $loop->defer(static function (): void {});

    expect(static fn() => $loop->run())
        ->toThrow(LogicException::class, 'requires an active Swoole/OpenSwoole host coroutine');
});
