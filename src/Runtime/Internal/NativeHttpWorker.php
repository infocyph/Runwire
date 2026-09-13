<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Runtime\Internal;

use Closure;
use Infocyph\Runwire\Http\HttpRequest;
use Infocyph\Runwire\Http\NativeHttpConnection;
use Infocyph\Runwire\Http\ResponseWriterInterface;
use Infocyph\Runwire\Loop\LoopInterface;
use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Network\Connection;
use Infocyph\Runwire\Network\Enum\CloseReason;
use Infocyph\Runwire\Runtime\RequestExecutionPolicy;
use Infocyph\Runwire\RuntimeContext;
use Infocyph\Runwire\Supervisor\WorkerContext;

final class NativeHttpWorker
{
    public static function run(
        WorkerContext $context,
        BoundServer $bound,
        RuntimeContext $runtimeContext,
        RequestExecutionPolicy $requestExecution,
    ): void {
        $loop = new SelectLoop();
        $sessions = [];
        $connections = [];
        $state = new WorkerStopState();
        $handler = self::requestHandler($bound, $context, $runtimeContext, $requestExecution);

        $bound->listener->start(
            $loop,
            static function (Connection $connection) use ($loop, $bound, $handler, &$sessions, &$connections, $state): void {
                self::attachConnection($connection, $loop, $bound, $handler, $sessions, $connections, $state);
            },
        );
        $loop->onReadable(
            $context->stopStream(),
            static function () use ($context, $bound, $loop, &$sessions, &$connections, $state): void {
                self::beginDrain($context, $bound, $loop, $sessions, $connections, $state);
            },
        );

        $context->ready();
        $loop->run();
        $bound->listener->close();
    }

    /**
     * @param Closure(HttpRequest, ResponseWriterInterface): void $handler
     * @param array<int, NativeHttpConnection> $sessions
     * @param array<int, Connection> $connections
     */
    private static function attachConnection(
        Connection $connection,
        LoopInterface $loop,
        BoundServer $bound,
        Closure $handler,
        array &$sessions,
        array &$connections,
        WorkerStopState $state,
    ): void {
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
    }

    /**
     * @param array<int, NativeHttpConnection> $sessions
     * @param array<int, Connection> $connections
     */
    private static function beginDrain(
        WorkerContext $context,
        BoundServer $bound,
        LoopInterface $loop,
        array &$sessions,
        array &$connections,
        WorkerStopState $state,
    ): void {
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
        if (!$context->recycling()) {
            return;
        }

        $loop->delay(
            $context->recyclePolicy->gracefulTimeoutSeconds,
            static function () use (&$connections, $loop): void {
                self::forceClose($connections, $loop);
            },
        );
    }

    /** @param array<int, Connection> $connections */
    private static function forceClose(array $connections, LoopInterface $loop): void
    {
        foreach ($connections as $connection) {
            $connection->abort(CloseReason::LOCAL_ABORT);
        }
        $loop->stop();
    }

    /** @return Closure(HttpRequest, ResponseWriterInterface): void */
    private static function requestHandler(
        BoundServer $bound,
        WorkerContext $context,
        RuntimeContext $runtimeContext,
        RequestExecutionPolicy $requestExecution,
    ): Closure {
        $applicationHandler = $bound->definition->handlerFor($context);

        return static function (HttpRequest $request, ResponseWriterInterface $writer) use (
            $applicationHandler,
            $context,
            $runtimeContext,
            $requestExecution,
        ): void {
            $request->context->activate($runtimeContext, $requestExecution);

            try {
                $applicationHandler($request, $writer);
            } finally {
                $request->context->complete();
                $context->recordRequestCompleted();
            }
        };
    }
}
