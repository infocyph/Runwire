<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Supervisor\Internal;

use Infocyph\Runwire\Exception\SupervisorException;

final class ChildReaper
{
    /**
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

    public static function waitFor(int $pid): void
    {
        do {
            $status = 0;
            $result = pcntl_waitpid($pid, $status);
        } while ($result === -1 && pcntl_get_last_error() === PCNTL_EINTR);
    }

    public static function exitCode(int $status): ?int
    {
        if (!pcntl_wifexited($status)) {
            return null;
        }

        $exitCode = pcntl_wexitstatus($status);
        return is_int($exitCode) ? $exitCode : null;
    }

    public static function termSignal(int $status): ?int
    {
        if (!pcntl_wifsignaled($status)) {
            return null;
        }

        $signal = pcntl_wtermsig($status);
        return is_int($signal) ? $signal : null;
    }
}
