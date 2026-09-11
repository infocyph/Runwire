<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Loop;

interface LoopInterface
{
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
    public function delay(float $seconds, callable $callback): int;

    /**
     * @param callable(int): void $callback
     */
    public function repeat(float $interval, callable $callback): int;

    /**
     * @param callable(int): void $callback
     */
    public function defer(callable $callback): int;

    public function cancel(int $id): bool;

    public function run(): void;

    public function stop(): void;

    public function now(): float;
}
