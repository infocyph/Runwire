<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Runtime\Internal;

use Infocyph\Runwire\Exception\ListenerException;
use Infocyph\Runwire\Http\Http3\Quic\PhpQuicHttp3Worker;
use Infocyph\Runwire\Http\Http3\Quic\PhpQuicListener;
use Infocyph\Runwire\Http\HttpRequest;
use Infocyph\Runwire\Http\ResponseWriterInterface;
use Infocyph\Runwire\Internal\MonotonicTime;
use Infocyph\Runwire\Loop\LoopInterface;
use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Metrics\DiagnosticsPolicy;
use Infocyph\Runwire\Metrics\Enum\ProtocolMetric;
use Infocyph\Runwire\Runtime\ApplicationLifecycleHooks;
use Infocyph\Runwire\Runtime\Enum\CancellationReason;
use Infocyph\Runwire\Runtime\RequestExecutionPolicy;
use Infocyph\Runwire\RuntimeContext;
use Infocyph\Runwire\Server;
use Infocyph\Runwire\Supervisor\WorkerContext;
use LogicException;
use Throwable;

/**
 * Runs native HTTP/3 QUIC handling inside native runtime workers.
 */
final class NativeHttp3Worker
{
    /**
     * Attach HTTP/3 polling to a shared native loop for portable single-process mode.
     */
    public static function attach(
        LoopInterface $loop,
        WorkerContext $context,
        Server $server,
        string $tcpAddress,
        RuntimeContext $runtimeContext,
        RequestExecutionPolicy $requestExecution,
        ApplicationLifecycleHooks $lifecycle,
        DiagnosticsPolicy $diagnostics = new DiagnosticsPolicy(),
    ): NativeWorkerHandle {
        $options = $server->http3;
        $tls = $server->tls;
        if ($options === null || $tls === null) {
            throw new LogicException('Native HTTP/3 workers require HTTP/3 and TLS server configuration.');
        }

        $taskLoop = new SelectLoop($diagnostics->callbackOverrunSeconds);
        $context->attachLoop($taskLoop);
        [$host, $port] = self::endpoint($tcpAddress);
        $listener = PhpQuicListener::bind(
            $host,
            $port,
            $options->listenerOptions($tls, false),
        );
        $application = $server->applicationFor(
            $context,
            $runtimeContext,
            $requestExecution,
            $lifecycle,
        );
        $sampler = new WorkerDiagnosticsSampler($context, $runtimeContext->metrics, $diagnostics, $taskLoop);
        $handler = self::requestHandler($application, $context, $sampler);
        $worker = new PhpQuicHttp3Worker(
            $listener,
            $handler,
            $options->limits,
            $context->admissionPolicy->connectionLimit($server->workerConnectionLimit),
            handshakeTimeoutSeconds: $options->handshakeTimeoutSeconds,
        );
        $draining = false;
        $drained = false;
        $shutdown = false;
        $drainDeadline = null;
        $pollTimer = null;
        $stopWatcher = null;

        $finishDrain = static function () use (
            $loop,
            $worker,
            &$pollTimer,
            &$drained,
            &$draining,
            &$drainDeadline,
        ): void {
            if (!$draining || $drained) {
                return;
            }
            if (!$worker->drainComplete() && ($drainDeadline === null || MonotonicTime::nowNanoseconds() < $drainDeadline)) {
                return;
            }
            if (!$worker->drainComplete()) {
                $worker->forceClose();
            }
            $drained = true;
            if ($pollTimer !== null) {
                $loop->cancel($pollTimer);
                $pollTimer = null;
            }
        };

        $beginDrain = static function () use (
            $context,
            $application,
            $worker,
            $sampler,
            &$draining,
            &$drainDeadline,
            $finishDrain,
        ): void {
            $context->consumeStopWake();
            if ($draining) {
                return;
            }

            $draining = true;
            $application->drain($context->shutdownReason());
            $worker->stopAccepting();
            $drainDeadline = MonotonicTime::deadlineAfterSeconds(
                MonotonicTime::nowNanoseconds(),
                $context->recyclePolicy->gracefulTimeoutSeconds,
            );
            $sampler->sample(true);
            $finishDrain();
        };

        try {
            $application->start();
            self::observeTransport($runtimeContext, $worker);
            $sampler->sample(true);
            $pollInterval = max(0.001, min(0.01, $options->pollTimeoutSeconds));
            $pollTimer = $loop->repeat(
                $pollInterval,
                static function () use (
                    $worker,
                    $taskLoop,
                    $runtimeContext,
                    $sampler,
                    $finishDrain,
                    &$drained,
                ): void {
                    if ($drained) {
                        return;
                    }

                    $worker->tick(0.0);
                    $taskLoop->tick();
                    self::observeTransport($runtimeContext, $worker);
                    $sampler->sample();
                    $finishDrain();
                },
            );
            $stopWatcher = $loop->onReadable(
                $context->stopStream(),
                static function () use ($beginDrain): void {
                    $beginDrain();
                },
            );
            $context->ready();
        } catch (Throwable $error) {
            $worker->stopAccepting();
            $worker->forceClose();
            $application->shutdown($context->shutdownReason());

            throw $error;
        }

        return new NativeWorkerHandle(
            stop: static function () use ($context): void {
                $context->requestStop();
            },
            forceStop: static function () use (
                $context,
                $beginDrain,
                $worker,
                $sampler,
                &$drained,
                &$pollTimer,
                $loop,
            ): void {
                $context->requestStop();
                $beginDrain();
                $worker->forceClose();
                $drained = true;
                if ($pollTimer !== null) {
                    $loop->cancel($pollTimer);
                    $pollTimer = null;
                }
                $sampler->sample(true);
            },
            close: static function () use (
                $loop,
                &$stopWatcher,
                &$pollTimer,
                $worker,
                $application,
                $context,
                $runtimeContext,
                $sampler,
                &$shutdown,
                &$drained,
            ): void {
                if ($stopWatcher !== null) {
                    $loop->cancel($stopWatcher);
                    $stopWatcher = null;
                }
                if ($pollTimer !== null) {
                    $loop->cancel($pollTimer);
                    $pollTimer = null;
                }
                $worker->stopAccepting();
                if (!$worker->drainComplete()) {
                    $worker->forceClose();
                }
                $drained = true;
                if (!$shutdown) {
                    $shutdown = true;
                    $application->shutdown($context->shutdownReason());
                }
                self::observeTransport($runtimeContext, $worker);
                $sampler->sample(true);
            },
            drained: static function () use (&$drained): bool {
                return $drained;
            },
        );
    }

    /**
     * Runs the HTTP/3 worker until shutdown and drains active connections on exit.
     */
    public static function run(
        WorkerContext $context,
        Server $server,
        string $tcpAddress,
        RuntimeContext $runtimeContext,
        RequestExecutionPolicy $requestExecution,
        ApplicationLifecycleHooks $lifecycle,
        DiagnosticsPolicy $diagnostics = new DiagnosticsPolicy(),
    ): void {
        $options = $server->http3;
        $tls = $server->tls;
        if ($options === null || $tls === null) {
            throw new LogicException('Native HTTP/3 workers require HTTP/3 and TLS server configuration.');
        }

        $taskLoop = new SelectLoop($diagnostics->callbackOverrunSeconds);
        $context->attachLoop($taskLoop);
        [$host, $port] = self::endpoint($tcpAddress);
        $listener = PhpQuicListener::bind(
            $host,
            $port,
            $options->listenerOptions($tls, $server->listener->reusePort),
        );
        $application = $server->applicationFor(
            $context,
            $runtimeContext,
            $requestExecution,
            $lifecycle,
        );
        $sampler = new WorkerDiagnosticsSampler($context, $runtimeContext->metrics, $diagnostics, $taskLoop);
        $handler = self::requestHandler($application, $context, $sampler);
        $worker = new PhpQuicHttp3Worker(
            $listener,
            $handler,
            $options->limits,
            $context->admissionPolicy->connectionLimit($server->workerConnectionLimit),
            handshakeTimeoutSeconds: $options->handshakeTimeoutSeconds,
        );

        try {
            $application->start();
            self::observeTransport($runtimeContext, $worker);
            $sampler->sample(true);
            $context->ready();
            while (!$context->stopping()) {
                $worker->tick($options->pollTimeoutSeconds);
                $taskLoop->tick();
                self::observeTransport($runtimeContext, $worker);
                $sampler->sample();
            }

            $context->consumeStopWake();
            $application->drain($context->shutdownReason());
            $worker->stopAccepting();
            $deadline = $context->recycling()
                ? MonotonicTime::deadlineAfterSeconds(
                    MonotonicTime::nowNanoseconds(),
                    $context->recyclePolicy->gracefulTimeoutSeconds,
                )
                : null;
            while (!$worker->drainComplete()) {
                if ($deadline !== null && MonotonicTime::nowNanoseconds() >= $deadline) {
                    $worker->forceClose();

                    break;
                }
                $worker->tick($options->pollTimeoutSeconds);
                self::observeTransport($runtimeContext, $worker);
                $sampler->sample();
            }
            self::observeTransport($runtimeContext, $worker);
            $sampler->sample(true);
        } finally {
            try {
                $worker->stopAccepting();
            } finally {
                $application->shutdown($context->shutdownReason());
                self::observeTransport($runtimeContext, $worker);
                $sampler->sample(true);
            }
        }
    }

    /** @return array{0: string, 1: int} */
    private static function endpoint(string $address): array
    {
        $uri = str_contains($address, '://') ? $address : 'tcp://' . $address;
        $parts = parse_url($uri);
        $host = is_array($parts) ? ($parts['host'] ?? null) : null;
        $port = is_array($parts) ? ($parts['port'] ?? null) : null;
        if (!is_string($host) || $host === '' || !is_int($port) || $port < 1) {
            throw new ListenerException(sprintf('Unable to derive HTTP/3 QUIC endpoint from TCP address "%s".', $address));
        }

        return [trim($host, '[]'), $port];
    }

    /** @return \Closure(HttpRequest, ResponseWriterInterface): void */
    private static function requestHandler(
        \Infocyph\Runwire\Runtime\RuntimeApplicationInterface $application,
        WorkerContext $context,
        WorkerDiagnosticsSampler $sampler,
    ): \Closure {
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

    private static function observeTransport(RuntimeContext $runtime, PhpQuicHttp3Worker $worker): void
    {
        $runtime->metrics->observeNetwork(
            activeConnections: $worker->connectionCount(),
            acceptedConnections: $worker->connectionsAcceptedTotal(),
            bytesRead: 0,
            bytesWritten: 0,
            rejectedConnections: 0,
        );
        $runtime->metrics->setProtocol(ProtocolMetric::HTTP3_CONNECTIONS_ACTIVE, $worker->connectionCount());
        $runtime->metrics->setProtocol(ProtocolMetric::HTTP3_CONNECTIONS_TOTAL, $worker->connectionsAcceptedTotal());
    }
}
