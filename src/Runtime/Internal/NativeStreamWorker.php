<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Runtime\Internal;

use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Network\Connection;
use Infocyph\Runwire\Network\UnixListener;
use Infocyph\Runwire\Protocol\FramedConnection;
use Infocyph\Runwire\Supervisor\WorkerContext;

final class NativeStreamWorker
{
    public static function run(WorkerContext $context, BoundStreamServer $bound): void
    {
        $loop = new SelectLoop();
        $sessions = [];
        $state = new WorkerStopState();
        $handler = $bound->definition->handlerFor($context);

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
                if ($state->isStopping() && $sessions === []) {
                    $loop->stop();
                }
            });
        });

        $loop->onReadable($context->stopStream(), function () use ($context, $bound, $loop, &$sessions, $state): void {
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
                $loop->stop();
            }
        });

        $context->ready();
        $loop->run();
        self::closeWorkerListener($bound);
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
