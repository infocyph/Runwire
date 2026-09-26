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

    /** @return list<int> */
    private static function descendants(int $pid): array
    {
        $descendants = [];
        $queue = [$pid];
        $seen = [$pid => true];

        while ($queue !== []) {
            $current = array_shift($queue);
            foreach (self::directChildren($current) as $child) {
                if (isset($seen[$child])) {
                    continue;
                }

                $seen[$child] = true;
                $descendants[] = $child;
                $queue[] = $child;
            }
        }

        return $descendants;
    }

    /** @return list<int> */
    private static function directChildren(int $pid): array
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            return [];
        }

        $path = sprintf('/proc/%d/task/%d/children', $pid, $pid);
        if (!is_readable($path)) {
            return [];
        }

        set_error_handler(static fn(int $severity): bool => $severity === E_WARNING);

        try {
            $content = file_get_contents($path);
        } finally {
            restore_error_handler();
        }

        if (!is_string($content) || trim($content) === '') {
            return [];
        }

        $children = [];
        foreach (preg_split('/\s+/', trim($content)) ?: [] as $value) {
            $child = (int) $value;
            if ($child > 1) {
                $children[] = $child;
            }
        }

        return $children;
    }

    /** @param resource $process */
    private static function terminate(mixed $process, ?int $pid, bool $processGroup, int $signal): bool
    {
        if (
            DIRECTORY_SEPARATOR !== '\\'
            && $pid !== null
            && $pid > 1
            && function_exists('posix_kill')
        ) {
            foreach (array_reverse(self::descendants($pid)) as $descendant) {
                posix_kill($descendant, $signal);
            }

            if ($processGroup) {
                return posix_kill(-$pid, $signal);
            }
        }

        return $signal === self::GRACEFUL_SIGNAL
            ? proc_terminate($process)
            : proc_terminate($process, self::FORCE_SIGNAL);
    }
}
