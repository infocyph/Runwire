<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Runtime\Internal;

use Infocyph\Runwire\Exception\ListenerException;
use Infocyph\Runwire\Http\Http3\Quic\PhpQuicHttp3Worker;
use Infocyph\Runwire\Http\Http3\Quic\PhpQuicListener;
use Infocyph\Runwire\Server;
use Infocyph\Runwire\Supervisor\WorkerContext;
use LogicException;

final class NativeHttp3Worker
{
    public static function run(WorkerContext $context, Server $server, string $tcpAddress): void
    {
        $options = $server->http3;
        $tls = $server->tls;
        if ($options === null || $tls === null) {
            throw new LogicException('Native HTTP/3 workers require HTTP/3 and TLS server configuration.');
        }

        [$host, $port] = self::endpoint($tcpAddress);
        $listener = PhpQuicListener::bind($host, $port, $options->listenerOptions($tls));
        $worker = new PhpQuicHttp3Worker(
            $listener,
            $server->handlerFor($context),
            $options->limits,
            $server->workerConnectionLimit,
            handshakeTimeoutSeconds: $options->handshakeTimeoutSeconds,
        );

        try {
            $context->ready();
            while (!$context->stopping()) {
                $worker->tick($options->pollTimeoutSeconds);
            }

            $context->consumeStopWake();
            $worker->stopAccepting();
            while (!$worker->drainComplete()) {
                $worker->tick($options->pollTimeoutSeconds);
            }
        } finally {
            $worker->stopAccepting();
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
