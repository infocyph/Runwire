<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Runtime\Internal;

use Closure;
use Infocyph\Runwire\Http\HttpRequest;
use Infocyph\Runwire\Http\NativeHttpConnection;
use Infocyph\Runwire\Http\ResponseWriterInterface;
use Infocyph\Runwire\Loop\LoopInterface;
use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Metrics\DiagnosticsPolicy;
use Infocyph\Runwire\Metrics\RuntimeMetrics;
use Infocyph\Runwire\Network\Connection;
use Infocyph\Runwire\Network\Enum\CloseReason;
use Infocyph\Runwire\Runtime\ApplicationLifecycle;
use Infocyph\Runwire\Runtime\ApplicationLifecycleHooks;
use Infocyph\Runwire\Runtime\Enum\CancellationReason;
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
        ApplicationLifecycleHooks $lifecycle,
        DiagnosticsPolicy $diagnostics = new DiagnosticsPolicy(),
    ): void {
        $loop = new SelectLoop($diagnostics->callbackOverrunSeconds);
        $sessions = [];
        $connections = [];
        $state = new WorkerStopState();
        $application = new ApplicationLifecycle(
            $bound->definition->handlerFor($context),
            $runtimeContext,
            $requestExecution,
            $lifecycle,
        );
        $sampler = new WorkerDiagnosticsSampler($context, $runtimeContext->metrics, $diagnostics, $loop);
        $handler = self::requestHandler($application, $context, $sampler);

        try {
            $application->start();
            $bound->listener->start(
                $loop,
                static function (Connection $connection) use (
                    $loop,
                    $bound,
                    $handler,
                    &$sessions,
                    &$connections,
                    $state,
                    $runtimeContext,
                    $sampler,
                ): void {
                    self::attachConnection(
                        $connection,
                        $loop,
                        $bound,
                        $handler,
                        $sessions,
                        $connections,
                        $state,
                        $runtimeContext->metrics,
                        $sampler,
                    );
                },
            );
            $loop->onReadable(
                $context->stopStream(),
                static function () use ($application, $context, $bound, $loop, &$sessions, &$connections, $state, $sampler): void {
                    self::beginDrain($application, $context, $bound, $loop, $sessions, $connections, $state);
                    $sampler->sample(true);
                },
            );

            $sampler->sample(true);
            $context->ready();
            $loop->run();
        } finally {
            $sampler->sample(true);

            try {
                $bound->listener->close();
            } finally {
                $application->shutdown($context->shutdownReason());
            }
        }
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
        RuntimeMetrics $metrics,
        WorkerDiagnosticsSampler $sampler,
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

        $metrics->connectionOpened($session->version);
        $id = spl_object_id($connection);
        $sessions[$id] = $session;
        $connections[$id] = $connection;
        $connection->onClose(static function () use (
            &$sessions,
            &$connections,
            $id,
            $loop,
            $state,
            $metrics,
            $sampler,
            $session,
            $connection,
        ): void {
            $metrics->connectionClosed(
                $session->version,
                $connection->bytesRead(),
                $connection->bytesWritten(),
                $connection->lifetimeNanoseconds(),
                $connection->backpressureEvents(),
            );
            unset($sessions[$id], $connections[$id]);
            $sampler->sample();
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
        ApplicationLifecycle $application,
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

        $application->drain($context->shutdownReason());
        $state->stop();
        $bound->listener->close();
        foreach ($sessions as $session) {
            $session->drain();
        }
        if ($sessions === []) {
            $loop->stop();
        } elseif ($context->recycling()) {
            $loop->delay(
                $context->recyclePolicy->gracefulTimeoutSeconds,
                static function () use (&$connections, $loop): void {
                    self::forceClose($connections, $loop);
                },
            );
        }
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
        ApplicationLifecycle $application,
        WorkerContext $context,
        WorkerDiagnosticsSampler $sampler,
    ): Closure {
        return static function (HttpRequest $request, ResponseWriterInterface $writer) use ($application, $context, $sampler): void {
            $context->recordRequestStarted();

            try {
                $application->handle($request, $writer);
            } finally {
                $context->recordRequestCompleted();
                if ($request->context->cancellation->reason() === CancellationReason::DEADLINE_EXCEEDED) {
                    $context->reportDeadlineExceeded($request->context->requestId);
                }
                $sampler->sample();
            }
        };
    }
}
