<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Loop\Internal;

use Closure;

/**
 * @internal
 * Defines the reactor operations needed by the Swoole-backed loop.
 */
interface SwooleReactorInterface
{
    /**
     * Register read and write callbacks for a stream.
     */
    public function add(mixed $stream, ?Closure $read, ?Closure $write, int $flags): bool;

    /**
     * Schedule a one-shot timer.
     */
    public function after(int $milliseconds, Closure $callback): int;

    /**
     * Cancel a timer.
     */
    public function clearTimer(int $timerId): bool;

    /**
     * Return the current host coroutine ID.
     */
    public function coroutineId(): int;

    /**
     * Defer a callback onto the reactor.
     */
    public function defer(Closure $callback): void;

    /**
     * Remove a stream registration.
     */
    public function delete(mixed $stream): bool;

    /**
     * Return the readable event flag.
     */
    public function readFlag(): int;

    /**
     * Schedule a repeating timer.
     */
    public function repeat(int $milliseconds, Closure $callback): int;

    /**
     * Resume a suspended host coroutine.
     */
    public function resumeCoroutine(int $coroutineId): bool;

    /**
     * Update callbacks and flags for an existing stream registration.
     */
    public function set(mixed $stream, ?Closure $read, ?Closure $write, int $flags): bool;

    /**
     * Suspend the current host coroutine.
     */
    public function suspendCoroutine(): void;

    /**
     * Return the writable event flag.
     */
    public function writeFlag(): int;
}
