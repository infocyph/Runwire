<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Loop;

/**
 * Defines timer, deferred, stream-watcher, and lifecycle operations for an event loop.
 */
interface LoopInterface
{
    /**
     * Cancel a registered loop handle.
     */
    public function cancel(int $id): bool;

    /**
     * @param callable(int): void $callback
     */
    public function defer(callable $callback): int;

    /**
     * @param callable(int): void $callback
     */
    public function delay(float $seconds, callable $callback): int;

    /**
     * Return the loop's current monotonic time in seconds.
     */
    public function now(): float;

    /**
     * @param resource $stream
     * @param callable(resource, int): void $callback
     */
    public function onReadable(mixed $stream, callable $callback): int;

    /**
     * @param resource $stream
     * @param callable(resource, int): void $callback
     */
    public function onWritable(mixed $stream, callable $callback): int;

    /**
     * @param callable(int): void $callback
     */
    public function repeat(float $interval, callable $callback): int;

    /**
     * Run until stopped or no referenced work remains.
     */
    public function run(): void;

    /**
     * Request that the running loop stop.
     */
    public function stop(): void;
}
