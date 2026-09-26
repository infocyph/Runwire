<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Process\Internal;

/**
 * Terminates owned child processes and their isolated POSIX process groups when available.
 *
 * @internal
 */
final readonly class ProcessTerminator
{
    private const int FORCE_SIGNAL = 9;

    private const int GRACEFUL_SIGNAL = 15;

    /** @param resource $process */
    public static function force(mixed $process, ?int $pid = null, bool $processGroup = false): bool
    {
        return self::terminate($process, $pid, $processGroup, self::FORCE_SIGNAL);
    }

    /** @param resource $process */
    public static function graceful(mixed $process, ?int $pid = null, bool $processGroup = false): bool
    {
        return self::terminate($process, $pid, $processGroup, self::GRACEFUL_SIGNAL);
    }

    /** @param resource $process */
    private static function terminate(mixed $process, ?int $pid, bool $processGroup, int $signal): bool
    {
        if (
            DIRECTORY_SEPARATOR !== '\\'
            && $processGroup
            && $pid !== null
            && $pid > 1
            && function_exists('posix_kill')
        ) {
            return posix_kill(-$pid, $signal);
        }

        return $signal === self::GRACEFUL_SIGNAL
            ? proc_terminate($process)
            : proc_terminate($process, self::FORCE_SIGNAL);
    }
}
