<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Tests\Support;

use RuntimeException;

/**
 * Validate the native wait-status output before decoding its exit/signal bits.
 *
 * @throws RuntimeException
 */
function waitStatus(mixed $value): int
{
    if (!is_int($value) || $value < 0) {
        throw new RuntimeException('Native wait status must be a non-negative integer.');
    }

    return $value;
}

/** @throws RuntimeException */
function restoreSignalHandler(int $signal, callable|int|string $handler): void
{
    if (is_string($handler) && !is_callable($handler)) {
        throw new RuntimeException('The previous signal handler cannot be restored.');
    }
    pcntl_signal($signal, $handler);
}

/** @throws RuntimeException */
function processId(): int
{
    $pid = getmypid();
    if ($pid === false || $pid < 1) {
        throw new RuntimeException('The current process identifier is unavailable.');
    }

    return $pid;
}

/** Query runtime symbols without assuming that native functions are enabled. */
function functionAvailable(string $name): bool
{
    return function_exists($name);
}

/**
 * Retire a forked fixture after runtime cleanup without running inherited Pest shutdown hooks.
 *
 * @throws RuntimeException
 */
function finishForkedChild(): never
{
    pcntl_exec(PHP_BINARY, ['-r', '']);

    throw new RuntimeException('Unable to replace the completed runtime fixture child.');
}
