<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Loop\Internal;

use Closure;
use Infocyph\Runwire\Exception\RuntimeUnavailableException;
use RuntimeException;

/** @internal */
final class NativeSwooleReactor implements SwooleReactorInterface
{
    /** @var class-string */
    private readonly string $constantClass;

    /** @var class-string */
    private readonly string $coroutineClass;

    /** @var class-string */
    private readonly string $eventClass;

    private readonly int $readFlagValue;

    /** @var class-string */
    private readonly string $timerClass;

    private readonly int $writeFlagValue;

    public function __construct()
    {
        [$this->eventClass, $this->timerClass, $this->coroutineClass, $this->constantClass]
            = self::resolveClassFamily();
        $this->readFlagValue = self::constantInt($this->constantClass . '::EVENT_READ');
        $this->writeFlagValue = self::constantInt($this->constantClass . '::EVENT_WRITE');
    }

    public function add(mixed $stream, ?Closure $read, ?Closure $write, int $flags): bool
    {
        $class = $this->eventClass;

        return $class::add($stream, $read, $write, $flags) === true;
    }

    public function after(int $milliseconds, Closure $callback): int
    {
        $class = $this->timerClass;
        $timerId = $class::after($milliseconds, $callback);
        if (!is_int($timerId)) {
            throw new RuntimeException('Swoole/OpenSwoole rejected the one-shot timer.');
        }

        return $timerId;
    }

    public function clearTimer(int $timerId): bool
    {
        $class = $this->timerClass;

        return $class::clear($timerId) === true;
    }

    public function coroutineId(): int
    {
        $class = $this->coroutineClass;
        $coroutineId = $class::getCid();

        return is_int($coroutineId) ? $coroutineId : -1;
    }

    public function defer(Closure $callback): void
    {
        $class = $this->eventClass;
        $class::defer($callback);
    }

    public function delete(mixed $stream): bool
    {
        $class = $this->eventClass;

        return $class::del($stream) === true;
    }

    public function readFlag(): int
    {
        return $this->readFlagValue;
    }

    public function repeat(int $milliseconds, Closure $callback): int
    {
        $class = $this->timerClass;
        $timerId = $class::tick($milliseconds, $callback);
        if (!is_int($timerId)) {
            throw new RuntimeException('Swoole/OpenSwoole rejected the repeating timer.');
        }

        return $timerId;
    }

    public function resumeCoroutine(int $coroutineId): bool
    {
        $class = $this->coroutineClass;

        return $class::resume($coroutineId) === true;
    }

    public function set(mixed $stream, ?Closure $read, ?Closure $write, int $flags): bool
    {
        $class = $this->eventClass;

        return $class::set($stream, $read, $write, $flags) === true;
    }

    public function suspendCoroutine(): void
    {
        $class = $this->coroutineClass;
        if ($class::yield() !== true) {
            throw new RuntimeException('Swoole/OpenSwoole failed to suspend the current host coroutine.');
        }
    }

    public function writeFlag(): int
    {
        return $this->writeFlagValue;
    }

    private static function constantInt(string $name): int
    {
        $value = defined($name) ? constant($name) : null;
        if (!is_int($value)) {
            throw new RuntimeUnavailableException(sprintf('Required Swoole/OpenSwoole constant %s is unavailable.', $name));
        }

        return $value;
    }

    /** @return array{class-string, class-string, class-string, class-string} */
    private static function resolveClassFamily(): array
    {
        foreach (['OpenSwoole', 'Swoole'] as $namespace) {
            $classes = [
                $namespace . '\\Event',
                $namespace . '\\Timer',
                $namespace . '\\Coroutine',
                $namespace . '\\Constant',
            ];
            if (class_exists($classes[0])
                && class_exists($classes[1])
                && class_exists($classes[2])
                && class_exists($classes[3])) {
                /** @var array{class-string, class-string, class-string, class-string} $classes */
                return $classes;
            }
        }

        throw new RuntimeUnavailableException(
            'The Swoole/OpenSwoole event, timer, coroutine, and constant APIs are unavailable.',
        );
    }
}
