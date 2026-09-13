<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Loop\Internal;

use Closure;

/** @internal */
interface SwooleReactorInterface
{
    public function add(mixed $stream, ?Closure $read, ?Closure $write, int $flags): bool;

    public function after(int $milliseconds, Closure $callback): int;

    public function clearTimer(int $timerId): bool;

    public function coroutineId(): int;

    public function defer(Closure $callback): void;

    public function delete(mixed $stream): bool;

    public function readFlag(): int;

    public function repeat(int $milliseconds, Closure $callback): int;

    public function resumeCoroutine(int $coroutineId): bool;

    public function set(mixed $stream, ?Closure $read, ?Closure $write, int $flags): bool;

    public function suspendCoroutine(): void;

    public function writeFlag(): int;
}
