<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Runtime\Internal;

use Infocyph\Runwire\Http\NativeHttpConnection;
use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Network\Connection;
use Infocyph\Runwire\Supervisor\WorkerContext;

final class NativeHttpWorker
{
    public static function run(WorkerContext $context, BoundServer $bound): void
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

            $session = NativeHttpConnection::attach(
                $loop,
                $connection,
                $handler,
                $bound->definition->http1,
                $bound->definition->http2,
            );
            if ($session === null) {
                return;
            }

            $id = spl_object_id($connection);
            $sessions[$id] = $session;
            $connection->onClose(static function () use (&$sessions, $id, $loop, $state): void {
                unset($sessions[$id]);
                if ($sessions === []) {
                    $state->stopLoopIfStopping($loop);
                }
            });
        });

        $loop->onReadable($context->stopStream(), function () use ($context, $bound, $loop, &$sessions, $state): void {
            $context->consumeStopWake();
            if ($state->isStopping()) {
                return;
            }

            $state->stop();
            $bound->listener->close();
            foreach ($sessions as $session) {
                $session->drain();
            }
            if ($sessions === []) {
                $loop->stop();
            }
        });

        $context->ready();
        $loop->run();
        $bound->listener->close();
    }
}
