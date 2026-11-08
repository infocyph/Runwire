<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Supervisor\Internal;

use Infocyph\Runwire\Exception\SupervisorException;
use Infocyph\Runwire\Internal\MonotonicTime;

/**
 * Normalizes child-process wait status and performs non-blocking reap cycles.
 */
final class ChildReaper
{
    /**
     * Extract the child exit code when the wait status represents a normal exit.
     */
    public static function exitCode(int $status): ?int
    {
        if (!pcntl_wifexited($status)) {
            return null;
        }

        $exitCode = pcntl_wexitstatus($status);

        return self::supportedStatus($exitCode);
    }

    /**
     * Force-stop owned children and return PIDs still unreaped after the deadline.
     *
     * @param array<int, ChildRecord> $children
     * @return list<int>
     */
    public static function forceKillAndWait(array $children, int $deadlineNanoseconds): array
    {
        foreach ($children as $record) {
            posix_kill($record->pid, SIGKILL);
        }
        $unreaped = [];
        foreach (array_keys($children) as $pid) {
            if (!self::waitForUntil($pid, $deadlineNanoseconds)) {
                $unreaped[] = $pid;
            }
        }

        return $unreaped;
    }

    /**
     * Reap all currently exited children and report when no children remain.
     *
     * @param callable(int, int): void $onExit
     * @param callable(): void $onNoChildren
     */
    public static function reap(callable $onExit, callable $onNoChildren): void
    {
        while (true) {
            $status = 0;
            $pid = pcntl_waitpid(-1, $status, WNOHANG);

            if ($pid > 0) {
                $exitStatus = self::supportedStatus($status);
                if ($exitStatus === null) {
                    throw new SupervisorException('waitpid returned a non-integer child status.');
                }
                $onExit($pid, $exitStatus);

                continue;
            }

            if ($pid === 0) {
                return;
            }

            $error = pcntl_get_last_error();
            if ($error === PCNTL_EINTR) {
                continue;
            }

            if ($error === PCNTL_ECHILD) {
                $onNoChildren();

                return;
            }

            throw new SupervisorException(sprintf('waitpid failed with PCNTL error %d.', $error));
        }
    }

    /**
     * Extract the terminating signal when the wait status represents a signaled exit.
     */
    public static function termSignal(int $status): ?int
    {
        if (!pcntl_wifsignaled($status)) {
            return null;
        }

        $signal = pcntl_wtermsig($status);

        return self::supportedStatus($signal);
    }

    /**
     * Poll for one child until it is reaped or the monotonic deadline expires.
     */
    public static function waitForUntil(int $pid, int $deadlineNanoseconds): bool
    {
        while (true) {
            $status = 0;
            $result = pcntl_waitpid($pid, $status, WNOHANG);
            if ($result === $pid) {
                return true;
            }
            if ($result === -1) {
                if (self::retryWait()) {
                    continue;
                }

                return true;
            }

            $now = MonotonicTime::nowNanoseconds();
            if ($now >= $deadlineNanoseconds) {
                return false;
            }

            usleep((int) min(1_000, max(1, ceil(($deadlineNanoseconds - $now) / 1_000))));
        }
    }

    /**
     * @throws \Infocyph\Runwire\Exception\SupervisorException
     */
    private static function retryWait(): bool
    {
        $error = pcntl_get_last_error();
        if ($error === PCNTL_EINTR) {
            return true;
        }
        if ($error === PCNTL_ECHILD) {
            return false;
        }

        throw new SupervisorException(sprintf('waitpid failed with PCNTL error %d.', $error));
    }

    /**
     * Keep the PCNTL false-on-unsupported-OS contract visible across analyzers.
     */
    private static function supportedStatus(mixed $status): ?int
    {
        return is_int($status) ? $status : null;
    }
}
