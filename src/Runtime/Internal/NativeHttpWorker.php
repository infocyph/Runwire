<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Runtime\Internal;

use Closure;
use Infocyph\Runwire\Http\HttpRequest;
use Infocyph\Runwire\Http\NativeHttpConnection;
use Infocyph\Runwire\Http\ResponseWriterInterface;
use Infocyph\Runwire\Loop\LoopFactory;
use Infocyph\Runwire\Loop\LoopInterface;
use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Metrics\DiagnosticsPolicy;
use Infocyph\Runwire\Metrics\RuntimeMetrics;
use Infocyph\Runwire\Network\Connection;
use Infocyph\Runwire\Network\Enum\CloseReason;
use Infocyph\Runwire\Runtime\ApplicationLifecycleHooks;
use Infocyph\Runwire\Runtime\Enum\CancellationReason;
use Infocyph\Runwire\Runtime\RequestExecutionPolicy;
use Infocyph\Runwire\Runtime\RuntimeApplicationInterface;
use Infocyph\Runwire\RuntimeContext;
use Infocyph\Runwire\Supervisor\WorkerContext;
use Throwable;

/**
 * Runs native HTTP/1.1 and HTTP/2 sessions inside native event loops.
 */
final class NativeHttpWorker
{
    private const int SELECT_LOOP_CONNECTION_LIMIT = 256;
    /**
     * Attach an HTTP worker to an existing loop without taking loop ownership.
     */
    public static function attach(
        LoopInterface $loop,
        WorkerContext $context,
        BoundServer $bound,
        RuntimeContext $runtimeContext,
        RequestExecutionPolicy $requestExecution,
        ApplicationLifecycleHooks $lifecycle,
        DiagnosticsPolicy $diagnostics = new DiagnosticsPolicy(),
        bool $ownsLoop = false,
    ): NativeWorkerHandle {
        $context->attachLoop($loop);
        $sessions = [];
        $connections = [];
        $state = new WorkerStopState($ownsLoop);
        $application = $bound->definition->applicationFor(
            $context,
            $runtimeContext,
            $requestExecution,
            $lifecycle,
            $loop,
        );
        $sampler = new WorkerDiagnosticsSampler($context, $runtimeContext->metrics, $diagnostics, $loop);
        $handler = self::requestHandler($application, $context, $sampler);
        $stopWatcher = null;
        $shutdown = false;

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
                    if (
                        $loop instanceof SelectLoop
                        && count($connections) >= self::SELECT_LOOP_CONNECTION_LIMIT
                    ) {
                        $connection->abort(CloseReason::RESOURCE_LIMIT);
                        $runtimeContext->metrics->recordRejectedConnection();
                        $sampler->sample();

                        return;
                    }

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
            $stopWatcher = $loop->onReadable(
                $context->stopStream(),
                static function () use ($application, $context, $bound, $loop, &$sessions, &$connections, $state, $sampler): void {
                    self::beginDrain($application, $context, $bound, $loop, $sessions, $connections, $state);
                    $sampler->sample(true);
                },
            );

            $sampler->sample(true);
            $context->ready();
        } catch (Throwable $error) {
            $failures = [];

            try {
                $bound->listener->close();
            } catch (Throwable $closeError) {
                $failures[] = $closeError;
            }

            ApplicationShutdown::finish(
                $application,
                $error,
                $context->shutdownReason(),
                $failures,
            );
        }

        return new NativeWorkerHandle(
            stop: static function () use ($context): void {
                $context->requestStop();
            },
            forceStop: static function () use (
                $application,
                $context,
                $bound,
                $loop,
                &$sessions,
                &$connections,
                $state,
                $sampler,
            ): void {
                $context->requestStop();
                self::beginDrain($application, $context, $bound, $loop, $sessions, $connections, $state);
                self::forceClose($connections, $loop, $state);
                $sampler->sample(true);
            },
            close: static function () use (
                $loop,
                &$stopWatcher,
                $bound,
                &$connections,
                $application,
                $context,
                $sampler,
                &$shutdown,
            ): void {
                if ($stopWatcher !== null) {
                    $loop->cancel($stopWatcher);
                    $stopWatcher = null;
                }
                foreach ($connections as $connection) {
                    $connection->abort(CloseReason::LOCAL_ABORT);
                }
                $sampler->sample(true);
                $bound->listener->close();
                if (!$shutdown) {
                    $shutdown = true;
                    $application->shutdown($context->shutdownReason());
                }
            },
            drained: static function () use (&$sessions, $state): bool {
                return $state->isStopping() && $sessions === [];
            },
        );
    }

    /**
     * Runs the bound HTTP listener until worker shutdown and drains active sessions.
     */
    public static function run(
        WorkerContext $context,
        BoundServer $bound,
        RuntimeContext $runtimeContext,
        RequestExecutionPolicy $requestExecution,
        ApplicationLifecycleHooks $lifecycle,
        DiagnosticsPolicy $diagnostics = new DiagnosticsPolicy(),
    ): void {
        $loop = LoopFactory::native($diagnostics);
        $handle = self::attach(
            $loop,
            $context,
            $bound,
            $runtimeContext,
            $requestExecution,
            $lifecycle,
            $diagnostics,
            ownsLoop: true,
        );

        $failure = null;

        try {
            $loop->run();
        } catch (Throwable $error) {
            $failure = $error;
        }

        $shutdownFailures = [];

        try {
            $handle->close();
        } catch (Throwable $error) {
            $shutdownFailures[] = $error;
        }

        ApplicationShutdown::resolve($failure, $shutdownFailures);
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
        RuntimeApplicationInterface $application,
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
            $state->stopLoopIfStopping($loop);
        } elseif ($context->recycling()) {
            $loop->delay(
                $context->recyclePolicy->gracefulTimeoutSeconds,
                static function () use (&$connections, $loop, $state): void {
                    self::forceClose($connections, $loop, $state);
                },
            );
        }
    }

    /** @param array<int, Connection> $connections */
    private static function forceClose(array $connections, LoopInterface $loop, WorkerStopState $state): void
    {
        foreach ($connections as $connection) {
            $connection->abort(CloseReason::LOCAL_ABORT);
        }
        $state->stopLoopIfStopping($loop);
    }

    /** @return Closure(HttpRequest, ResponseWriterInterface): void */
    private static function requestHandler(
        RuntimeApplicationInterface $application,
        WorkerContext $context,
        WorkerDiagnosticsSampler $sampler,
    ): Closure {
        return static function (HttpRequest $request, ResponseWriterInterface $writer) use ($application, $context, $sampler): void {
            $context->recordRequestStarted();
            $completed = false;
            $complete = static function () use (
                $application,
                $context,
                $request,
                $sampler,
                &$completed,
            ): void {
                if ($completed) {
                    return;
                }

                $completed = true;
                $context->recordRequestCompleted();
                if ($request->context->cancellation->reason() === CancellationReason::DEADLINE_EXCEEDED) {
                    $context->reportDeadlineExceeded($request->context->requestId);
                }
                if (!$application->healthy()) {
                    $context->requestStop();
                }
                $sampler->sample();
            };

            try {
                $application->handle($request, $writer);
            } catch (Throwable $error) {
                $complete();

                throw $error;
            }

            $writer->onTerminal(static function () use ($complete): void {
                $complete();
            });
            $request->context->cancellation->onCancel(static function () use ($complete): void {
                $complete();
            });
            if ($request->context->completed()) {
                $complete();
            }
        };
    }
}
