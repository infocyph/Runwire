<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Runtime\Internal;

use Infocyph\Runwire\Http\HttpRequest;
use Infocyph\Runwire\Http\NativeHttpConnection;
use Infocyph\Runwire\Http\ResponseWriterInterface;
use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Network\Connection;
use Infocyph\Runwire\Network\Enum\CloseReason;
use Infocyph\Runwire\Supervisor\WorkerContext;

final class NativeHttpWorker
{
    public static function run(WorkerContext $context, BoundServer $bound): void
    {
        $loop = new SelectLoop();
        $sessions = [];
        $connections = [];
        $state = new WorkerStopState();
        $applicationHandler = $bound->definition->handlerFor($context);
        $handler = static function (HttpRequest $request, ResponseWriterInterface $writer) use ($applicationHandler, $context): void {
            try {
                $applicationHandler($request, $writer);
            } finally {
                $context->recordRequestCompleted();
            }
        };

        $bound->listener->start($loop, function (Connection $connection) use ($loop, $bound, $handler, &$sessions, &$connections, $state): void {
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
            $connections[$id] = $connection;
            $connection->onClose(static function () use (&$sessions, &$connections, $id, $loop, $state): void {
                unset($sessions[$id], $connections[$id]);
                if ($sessions === []) {
                    $state->stopLoopIfStopping($loop);
                }
            });
        });

        $loop->onReadable($context->stopStream(), function () use ($context, $bound, $loop, &$sessions, &$connections, $state): void {
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

                return;
            }

            if ($context->recycling()) {
                $loop->delay(
                    $context->recyclePolicy->gracefulTimeoutSeconds,
                    static function () use (&$connections, $loop): void {
                        foreach ($connections as $connection) {
                            $connection->abort(CloseReason::LOCAL_ABORT);
                        }
                        $loop->stop();
                    },
                );
            }
        });

        $context->ready();
        $loop->run();
        $bound->listener->close();
    }
}
