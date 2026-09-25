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
        $attachment = new NativeHttp3Attachment(
            $loop,
            $taskLoop,
            $context,
            $application,
            $worker,
            $sampler,
            static fn() => self::observeTransport($runtimeContext, $worker),
        );

        return $attachment->start(max(0.001, min(0.01, $options->pollTimeoutSeconds)));
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

        $failure = null;

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
        } catch (Throwable $error) {
            $failure = $error;
        }

        $shutdownFailures = [];

        try {
            $worker->stopAccepting();
        } catch (Throwable $error) {
            $shutdownFailures[] = $error;
        }

        try {
            $application->shutdown($context->shutdownReason());
        } catch (Throwable $error) {
            $shutdownFailures[] = $error;
        }

        try {
            self::observeTransport($runtimeContext, $worker);
            $sampler->sample(true);
        } catch (Throwable $error) {
            $shutdownFailures[] = $error;
        }

        ApplicationShutdown::resolve($failure, $shutdownFailures);
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

    /** @return \Closure(HttpRequest, ResponseWriterInterface): void */
    private static function requestHandler(
        \Infocyph\Runwire\Runtime\RuntimeApplicationInterface $application,
        WorkerContext $context,
        WorkerDiagnosticsSampler $sampler,
    ): \Closure {
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
