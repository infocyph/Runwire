<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Supervisor\Internal;

use Infocyph\Runwire\Exception\ApplicationStartupException;
use Infocyph\Runwire\Exception\SupervisorException;
use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Runtime\Enum\ApplicationStartupPhase;
use Infocyph\Runwire\Supervisor\WorkerContext;
use Infocyph\Runwire\Supervisor\WorkerGroup;
use Throwable;

final class WorkerChildRuntime
{
    public const int RECYCLE_EXIT_CODE = 75;

    public const int WARMUP_FAILURE_EXIT_CODE = 78;

    /** @param resource $readyStream */
    public static function run(
        WorkerGroup $group,
        int $slot,
        int $generation,
        mixed $readyStream,
    ): never {
        $context = null;

        try {
            $backgroundLoop = $group->role->background() ? new SelectLoop() : null;
            $context = new WorkerContext(
                group: $group->name,
                slot: $slot,
                generation: $generation,
                pid: posix_getpid(),
                parentPid: posix_getppid(),
                readyStream: $readyStream,
                recyclePolicy: $group->recyclePolicy,
                admissionPolicy: $group->admissionPolicy,
                role: $group->role,
            );
            if ($backgroundLoop !== null) {
                $context->attachLoop($backgroundLoop, $group->shutdownTimeoutSeconds);
            }

            pcntl_async_signals(true);
            $stopHandler = static function () use ($context): void {
                $context->requestStop();
            };

            if (!pcntl_signal(SIGTERM, $stopHandler) || !pcntl_signal(SIGINT, $stopHandler)) {
                throw new SupervisorException('Unable to install worker stop signal handlers.');
            }

            $group->privilegeDropPolicy->apply();
            if ($group->automaticReady) {
                $context->ready();
            }

            ($group->bootstrap)($context);
            if ($backgroundLoop !== null) {
                $backgroundLoop->onReadable(
                    $context->stopStream(),
                    static function () use ($backgroundLoop, $context): void {
                        $context->consumeStopWake();
                        if ($context->backgroundTaskCount() === 0) {
                            $backgroundLoop->stop();
                        }
                    },
                );
                if (!$context->stopping() || $context->backgroundTaskCount() > 0) {
                    $backgroundLoop->run();
                }
            }

            $exitCode = $context->recycling() ? self::RECYCLE_EXIT_CODE : 0;
            $context->close();
            self::terminate($exitCode);
        } catch (Throwable $error) {
            $warmupFailure = $error instanceof ApplicationStartupException
                && $error->phase === ApplicationStartupPhase::WARMUP;
            if ($warmupFailure) {
                $context?->reportWarmupFailure();
            }
            $context?->close();
            self::terminate($warmupFailure ? self::WARMUP_FAILURE_EXIT_CODE : 70);
        }
    }

    private static function terminate(int $status): never
    {
        (\exit(...))($status);
    }
}
