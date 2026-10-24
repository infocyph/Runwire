<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Runtime\Internal;

use Infocyph\Runwire\Loop\LoopInterface;
use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Metrics\DiagnosticsPolicy;
use Infocyph\Runwire\Network\Connection;
use Infocyph\Runwire\Network\UnixListener;
use Infocyph\Runwire\Protocol\FramedConnection;
use Infocyph\Runwire\Supervisor\WorkerContext;

/**
 * Runs framed TCP or Unix-domain stream sessions inside native event loops.
 */
final class NativeStreamWorker
{
    /**
     * Attach a stream worker to an existing loop without taking loop ownership.
     */
    public static function attach(
        LoopInterface $loop,
        WorkerContext $context,
        BoundStreamServer $bound,
        bool $ownsLoop = false,
    ): NativeWorkerHandle {
        $context->attachLoop($loop);
        $sessions = [];
        $state = new WorkerStopState($ownsLoop);
        $handler = $bound->definition->handlerFor($context);
        $stopWatcher = null;

        $bound->listener->start($loop, function (Connection $connection) use ($loop, $bound, $handler, &$sessions, $state): void {
            if ($state->isStopping()) {
                $connection->closeGracefully();

                return;
            }
            $session = new FramedConnection(
                $loop,
                $connection,
                $bound->definition->codec(),
                $handler,
                $bound->definition->maxFramesPerTick,
            );
            $id = spl_object_id($connection);
            $sessions[$id] = $session;
            $connection->onClose(static function () use (&$sessions, $id, $loop, $state): void {
                unset($sessions[$id]);
                if ($sessions === []) {
                    $state->stopLoopIfStopping($loop);
                }
            });
        });

        $stopWatcher = $loop->onReadable(
            $context->stopStream(),
            static function () use ($context, $bound, $loop, &$sessions, $state): void {
                self::beginDrain($context, $bound, $loop, $sessions, $state);
            },
        );

        $context->ready();

        return new NativeWorkerHandle(
            stop: static function () use ($context): void {
                $context->requestStop();
            },
            forceStop: static function () use ($context, $bound, $loop, &$sessions, $state): void {
                $context->requestStop();
                self::beginDrain($context, $bound, $loop, $sessions, $state);
                foreach ($sessions as $session) {
                    $session->abort();
                }
                $state->stopLoopIfStopping($loop);
            },
            close: static function () use ($loop, &$stopWatcher, $bound, &$sessions): void {
                if ($stopWatcher !== null) {
                    $loop->cancel($stopWatcher);
                    $stopWatcher = null;
                }
                foreach ($sessions as $session) {
                    $session->abort();
                }
                self::closeWorkerListener($bound);
            },
            drained: static function () use (&$sessions, $state): bool {
                return $state->isStopping() && $sessions === [];
            },
        );
    }

    /**
     * Runs the bound stream listener until worker shutdown is requested.
     */
    public static function run(
        WorkerContext $context,
        BoundStreamServer $bound,
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

    /** @param array<int, FramedConnection> $sessions */
    private static function beginDrain(
        WorkerContext $context,
        BoundStreamServer $bound,
        LoopInterface $loop,
        array &$sessions,
        WorkerStopState $state,
    ): void {
        $context->consumeStopWake();
        if ($state->isStopping()) {
            return;
        }

        $state->stop();
        self::closeWorkerListener($bound);
        foreach ($sessions as $session) {
            $session->closeGracefully();
        }
        if ($sessions === []) {
            $state->stopLoopIfStopping($loop);
        }
    }

    private static function closeWorkerListener(BoundStreamServer $bound): void
    {
        if ($bound->listener instanceof UnixListener) {
            $bound->listener->close(false);

            return;
        }
        $bound->listener->close();
    }
}
