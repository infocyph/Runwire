<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Process\Internal;

/**
 * Terminates proc_open() children without depending on PCNTL signal constants.
 *
 * @internal
 */
final readonly class ProcessTerminator
{
    private const int FORCE_SIGNAL = 9;

    /** @param resource $process */
    public static function force(mixed $process): bool
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            return proc_terminate($process);
        }

        return proc_terminate($process, self::FORCE_SIGNAL);
    }

    /** @param resource $process */
    public static function graceful(mixed $process): bool
    {
        return proc_terminate($process);
    }
}
