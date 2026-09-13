<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Loop\Internal;

use Closure;
use Infocyph\Runwire\Exception\RuntimeUnavailableException;
use ReflectionException;
use ReflectionMethod;
use RuntimeException;

/** @internal */
final readonly class NativeSwooleReactor implements SwooleReactorInterface
{
    private Closure $coroutineGetCid;

    private Closure $coroutineResume;

    private Closure $coroutineYield;

    private Closure $eventAdd;

    private Closure $eventDefer;

    private Closure $eventDelete;

    private Closure $eventSet;

    private int $readFlagValue;

    private Closure $timerAfter;

    private Closure $timerClear;

    private Closure $timerRepeat;

    private int $writeFlagValue;

    public function __construct()
    {
        [$eventClass, $timerClass, $coroutineClass, $namespace] = self::resolveClassFamily();
        $this->coroutineGetCid = self::method($coroutineClass, 'getCid');
        $this->coroutineResume = self::method($coroutineClass, 'resume');
        $this->coroutineYield = self::method($coroutineClass, 'yield');
        $this->eventAdd = self::method($eventClass, 'add');
        $this->eventDefer = self::method($eventClass, 'defer');
        $this->eventDelete = self::method($eventClass, 'del');
        $this->eventSet = self::method($eventClass, 'set');
        $this->readFlagValue = self::eventFlag($namespace, 'READ');
        $this->timerAfter = self::method($timerClass, 'after');
        $this->timerClear = self::method($timerClass, 'clear');
        $this->timerRepeat = self::method($timerClass, 'tick');
        $this->writeFlagValue = self::eventFlag($namespace, 'WRITE');
    }

    public function add(mixed $stream, ?Closure $read, ?Closure $write, int $flags): bool
    {
        return ($this->eventAdd)($stream, $read, $write, $flags) === true;
    }

    public function after(int $milliseconds, Closure $callback): int
    {
        $timerId = ($this->timerAfter)($milliseconds, $callback);
        if (!is_int($timerId)) {
            throw new RuntimeException('Swoole/OpenSwoole rejected the one-shot timer.');
        }

        return $timerId;
    }

    public function clearTimer(int $timerId): bool
    {
        return ($this->timerClear)($timerId) === true;
    }

    public function coroutineId(): int
    {
        $coroutineId = ($this->coroutineGetCid)();

        return is_int($coroutineId) ? $coroutineId : -1;
    }

    public function defer(Closure $callback): void
    {
        ($this->eventDefer)($callback);
    }

    public function delete(mixed $stream): bool
    {
        return ($this->eventDelete)($stream) === true;
    }

    public function readFlag(): int
    {
        return $this->readFlagValue;
    }

    public function repeat(int $milliseconds, Closure $callback): int
    {
        $timerId = ($this->timerRepeat)($milliseconds, $callback);
        if (!is_int($timerId)) {
            throw new RuntimeException('Swoole/OpenSwoole rejected the repeating timer.');
        }

        return $timerId;
    }

    public function resumeCoroutine(int $coroutineId): bool
    {
        return ($this->coroutineResume)($coroutineId) === true;
    }

    public function set(mixed $stream, ?Closure $read, ?Closure $write, int $flags): bool
    {
        return ($this->eventSet)($stream, $read, $write, $flags) === true;
    }

    public function suspendCoroutine(): void
    {
        if (($this->coroutineYield)() !== true) {
            throw new RuntimeException('Swoole/OpenSwoole failed to suspend the current host coroutine.');
        }
    }

    public function writeFlag(): int
    {
        return $this->writeFlagValue;
    }

    private static function eventFlag(string $namespace, string $direction): int
    {
        $candidates = array_values(array_unique([
            $namespace . '\\Constant::EVENT_' . $direction,
            $namespace . '\\Socket::EVENT_' . $direction,
            strtoupper($namespace) . '_EVENT_' . $direction,
            'SWOOLE_EVENT_' . $direction,
        ]));

        foreach ($candidates as $name) {
            $value = defined($name) ? constant($name) : null;
            if (is_int($value)) {
                return $value;
            }
        }

        throw new RuntimeUnavailableException(sprintf(
            'Required %s event flag is unavailable from the Swoole/OpenSwoole runtime.',
            strtolower($direction),
        ));
    }

    /** @param class-string $class */
    private static function method(string $class, string $method): Closure
    {
        try {
            return new ReflectionMethod($class, $method)->getClosure();
        } catch (ReflectionException) {
            throw new RuntimeUnavailableException(sprintf(
                'Required Swoole/OpenSwoole method %s::%s is unavailable.',
                $class,
                $method,
            ));
        }
    }

    /** @return array{class-string, class-string, class-string, string} */
    private static function resolveClassFamily(): array
    {
        foreach (['OpenSwoole', 'Swoole'] as $namespace) {
            $classes = [
                $namespace . '\\Event',
                $namespace . '\\Timer',
                $namespace . '\\Coroutine',
            ];
            if (class_exists($classes[0])
                && class_exists($classes[1])
                && class_exists($classes[2])) {
                /** @var array{class-string, class-string, class-string} $classes */
                return [$classes[0], $classes[1], $classes[2], $namespace];
            }
        }

        throw new RuntimeUnavailableException(
            'The Swoole/OpenSwoole event, timer, and coroutine APIs are unavailable.',
        );
    }
}
