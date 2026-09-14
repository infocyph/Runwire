<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Supervisor\Internal;

use Infocyph\Runwire\Exception\SupervisorException;

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

        return is_int($exitCode) ? $exitCode : null;
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
                if (!is_int($status)) {
                    throw new SupervisorException('waitpid returned a non-integer child status.');
                }
                $onExit($pid, $status);

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

        return is_int($signal) ? $signal : null;
    }

    /**
     * Wait synchronously for one child process, retrying interrupted waits.
     */
    public static function waitFor(int $pid): void
    {
        do {
            $status = 0;
            $result = pcntl_waitpid($pid, $status);
        } while ($result === -1 && pcntl_get_last_error() === PCNTL_EINTR);
    }
}
