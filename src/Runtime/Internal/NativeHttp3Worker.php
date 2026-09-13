<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Runtime\Internal;

use Infocyph\Runwire\Exception\ListenerException;
use Infocyph\Runwire\Http\Http3\Quic\PhpQuicHttp3Worker;
use Infocyph\Runwire\Http\Http3\Quic\PhpQuicListener;
use Infocyph\Runwire\Http\HttpRequest;
use Infocyph\Runwire\Http\ResponseWriterInterface;
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

final class NativeHttp3Worker
{
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
        $handler = static function (HttpRequest $request, ResponseWriterInterface $writer) use ($application, $context, $sampler): void {
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
                ? hrtime(true) + (int) ($context->recyclePolicy->gracefulTimeoutSeconds * 1_000_000_000)
                : null;
            while (!$worker->drainComplete()) {
                if ($deadline !== null && hrtime(true) >= $deadline) {
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
