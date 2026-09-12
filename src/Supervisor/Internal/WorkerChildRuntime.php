<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Supervisor\Internal;

use Infocyph\Runwire\Exception\SupervisorException;
use Infocyph\Runwire\Supervisor\WorkerContext;
use Infocyph\Runwire\Supervisor\WorkerGroup;
use Throwable;

final class WorkerChildRuntime
{
    public const int RECYCLE_EXIT_CODE = 75;

    /** @param resource $readyStream */
    public static function run(
        WorkerGroup $group,
        int $slot,
        int $generation,
        mixed $readyStream,
    ): never {
        $context = null;

        try {
            $context = new WorkerContext(
                group: $group->name,
                slot: $slot,
                generation: $generation,
                pid: posix_getpid(),
                parentPid: posix_getppid(),
                readyStream: $readyStream,
                recyclePolicy: $group->recyclePolicy,
            );

            pcntl_async_signals(true);
            $stopHandler = static function () use ($context): void {
                $context->requestStop();
            };

            if (!pcntl_signal(SIGTERM, $stopHandler) || !pcntl_signal(SIGINT, $stopHandler)) {
                throw new SupervisorException('Unable to install worker stop signal handlers.');
            }

            if ($group->automaticReady) {
                $context->ready();
            }

            ($group->bootstrap)($context);
            $exitCode = $context->recycling() ? self::RECYCLE_EXIT_CODE : 0;
            $context->close();
            self::terminate($exitCode);
        } catch (Throwable) {
            $context?->close();
            self::terminate(70);
        }
    }

    private static function terminate(int $status): never
    {
        (\exit(...))($status);
    }
}
