<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Runtime\Internal;

use Infocyph\Runwire\Loop\LoopInterface;
use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Metrics\DiagnosticsPolicy;
use Infocyph\Runwire\Supervisor\WorkerContext;

/**
 * Runs native datagram servers inside native event loops.
 */
final class NativeDatagramWorker
{
    /**
     * Attach a datagram worker to an existing loop without taking loop ownership.
     */
    public static function attach(
        LoopInterface $loop,
        WorkerContext $context,
        BoundDatagramServer $bound,
        bool $ownsLoop = false,
    ): NativeWorkerHandle {
        $context->attachLoop($loop);
        $handler = $bound->definition->handlerFor($context);
        $drained = false;
        $stopWatcher = null;
        $bound->listener->start($loop, $handler);

        $stop = static function () use ($context, $bound, $loop, $ownsLoop, &$drained): void {
            if ($drained) {
                return;
            }

            $context->consumeStopWake();
            $bound->listener->close();
            $drained = true;
            if ($ownsLoop) {
                $loop->stop();
            }
        };

        $stopWatcher = $loop->onReadable(
            $context->stopStream(),
            static function () use ($stop): void {
                $stop();
            },
        );

        $context->ready();

        return new NativeWorkerHandle(
            stop: static function () use ($context): void {
                $context->requestStop();
            },
            forceStop: static function () use ($context, $stop): void {
                $context->requestStop();
                $stop();
            },
            close: static function () use ($loop, &$stopWatcher, $bound, &$drained): void {
                if ($stopWatcher !== null) {
                    $loop->cancel($stopWatcher);
                    $stopWatcher = null;
                }
                $bound->listener->close();
                $drained = true;
            },
            drained: static function () use (&$drained): bool {
                return $drained;
            },
        );
    }

    /**
     * Runs the bound datagram listener until worker shutdown is requested.
     */
    public static function run(
        WorkerContext $context,
        BoundDatagramServer $bound,
        DiagnosticsPolicy $diagnostics = new DiagnosticsPolicy(),
    ): void {
        $loop = new SelectLoop($diagnostics->callbackOverrunSeconds);
        $handle = self::attach($loop, $context, $bound, ownsLoop: true);

        try {
            $loop->run();
        } finally {
            $handle->close();
        }
    }
}
