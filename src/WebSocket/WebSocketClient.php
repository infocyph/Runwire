<?php

declare(strict_types=1);

namespace Infocyph\Runwire\WebSocket;

use Infocyph\Runwire\Loop\LoopInterface;
use Infocyph\Runwire\Network\Connection;
use Infocyph\Runwire\Network\DialEndpoint;
use Infocyph\Runwire\Network\DialOptions;
use Infocyph\Runwire\Network\HappyEyeballsDialer;
use Infocyph\Runwire\Network\TlsClientOptions;
use Infocyph\Runwire\WebSocket\Internal\WebSocketClientHandshake;
use Infocyph\Runwire\WebSocket\Internal\WebSocketClientUpgrade;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/** Outbound HTTP/1.1 WebSocket upgrade over the shared verified TCP/TLS dialer. */
final readonly class WebSocketClient
{
    /**
     * Start a nonblocking ws/wss connection. Caller owns the dialer and received session.
     *
     * @param callable(?WebSocketSession, ?Throwable):void $completion
     * @param list<string> $subprotocols
     */
    public static function connect(
        LoopInterface $loop,
        HappyEyeballsDialer $dialer,
        string $uri,
        callable $completion,
        array $subprotocols = [],
        ?DialOptions $dialOptions = null,
        ?WebSocketOptions $options = null,
    ): int {
        $request = WebSocketClientHandshake::build($uri, $subprotocols);
        $dialOptions ??= new DialOptions();
        $dialOptions = self::verifiedDialOptions($request, $dialOptions);
        $endpoint = DialEndpoint::parse('tcp://' . $request['host'] . ':' . $request['port']);

        return $dialer->connect(
            $endpoint,
            static function (?Connection $connection, ?Throwable $error) use (
                $loop,
                $completion,
                $request,
                $subprotocols,
                $dialOptions,
                $options,
            ): void {
                if ($error !== null || $connection === null) {
                    $completion(null, $error ?? new RuntimeException('WebSocket transport was not established.'));

                    return;
                }
                if ($connection->negotiatedProtocol() !== null
                    && $connection->negotiatedProtocol() !== 'http/1.1') {
                    $connection->abort();
                    $completion(null, new RuntimeException('WebSocket HTTP/1.1 upgrade requires HTTP/1.1 ALPN.'));

                    return;
                }

                new WebSocketClientUpgrade(
                    $loop,
                    $connection,
                    $request,
                    $subprotocols,
                    $options ?? new WebSocketOptions(),
                    $dialOptions,
                    $completion,
                )->start();
            },
            $dialOptions,
        );
    }

    /**
     * @param array{wire:string,key:string,secure:bool,host:string,port:int} $request
     */
    private static function verifiedDialOptions(array $request, DialOptions $options): DialOptions
    {
        $tls = $options->tls;
        if (!$request['secure']) {
            if ($tls !== null) {
                throw new InvalidArgumentException('ws:// cannot silently apply a TLS policy; use wss://.');
            }

            return $options;
        }
        $peerName = trim($request['host'], '[]');
        $tls ??= new TlsClientOptions($peerName, alpnProtocols: ['http/1.1']);
        if (strcasecmp($tls->peerName, $peerName) !== 0 || $tls->alpnProtocols !== ['http/1.1']) {
            throw new InvalidArgumentException('WebSocket TLS policy must verify the exact host using HTTP/1.1 ALPN.');
        }

        return new DialOptions(
            connectTimeoutSeconds: $options->connectTimeoutSeconds,
            deadline: $options->deadline,
            cancellation: $options->cancellation,
            connectionLimits: $options->connectionLimits,
            tcpNoDelay: $options->tcpNoDelay,
            tls: $tls,
            addressPolicy: $options->addressPolicy,
        );
    }
}
