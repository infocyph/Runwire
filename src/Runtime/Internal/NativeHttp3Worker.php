<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Runtime\Internal;

use Infocyph\Runwire\Exception\ListenerException;
use Infocyph\Runwire\Http\Http3\Quic\PhpQuicHttp3Worker;
use Infocyph\Runwire\Http\Http3\Quic\PhpQuicListener;
use Infocyph\Runwire\Http\HttpRequest;
use Infocyph\Runwire\Http\ResponseWriterInterface;
use Infocyph\Runwire\Runtime\ApplicationLifecycle;
use Infocyph\Runwire\Runtime\ApplicationLifecycleHooks;
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
    ): void {
        $options = $server->http3;
        $tls = $server->tls;
        if ($options === null || $tls === null) {
            throw new LogicException('Native HTTP/3 workers require HTTP/3 and TLS server configuration.');
        }

        [$host, $port] = self::endpoint($tcpAddress);
        $listener = PhpQuicListener::bind($host, $port, $options->listenerOptions($tls));
        $application = new ApplicationLifecycle(
            $server->handlerFor($context),
            $runtimeContext,
            $requestExecution,
            $lifecycle,
        );
        $handler = static function (HttpRequest $request, ResponseWriterInterface $writer) use ($application, $context): void {
            $context->recordRequestStarted();
            try {
                $application->handle($request, $writer);
            } finally {
                $context->recordRequestCompleted();
            }
        };
        $worker = new PhpQuicHttp3Worker(
            $listener,
            $handler,
            $options->limits,
            $server->workerConnectionLimit,
            handshakeTimeoutSeconds: $options->handshakeTimeoutSeconds,
        );

        try {
            $application->start();
            $context->ready();
            while (!$context->stopping()) {
                $worker->tick($options->pollTimeoutSeconds);
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
            }
        } finally {
            try {
                $worker->stopAccepting();
            } finally {
                $application->shutdown($context->shutdownReason());
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
}
