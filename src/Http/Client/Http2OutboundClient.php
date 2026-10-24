<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Client;

use Infocyph\Runwire\Http\Enum\ProtocolVersion;
use Infocyph\Runwire\Loop\LoopInterface;
use Infocyph\Runwire\Network\Connection;
use Infocyph\Runwire\Network\DialOptions;
use Infocyph\Runwire\Network\HappyEyeballsDialer;
use Infocyph\Runwire\Network\TlsClientOptions;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Experimental single-stream H2 client using the established verified dialer.
 *
 * H2-only TLS and strict policy; no plaintext fallback, multiplexing,
 * connection pooling, redirects, trailers or push are implemented.
 */
final readonly class Http2OutboundClient
{
    /**
     * Start one verified TLS+h2 request and stream response callbacks.
     *
     * @param callable(int,\Infocyph\Runwire\Http\Headers):void $onHeaders
     * @param callable(string):void $onBody
     * @param callable(?Connection,?Throwable):void $onComplete
     */
    public static function connect(
        LoopInterface $loop,
        HappyEyeballsDialer $dialer,
        ClientRequest $request,
        callable $onHeaders,
        callable $onBody,
        callable $onComplete,
        ?DialOptions $options = null,
    ): int {
        $parts = parse_url($request->uri);
        if (!is_array($parts) || strtolower($parts['scheme'] ?? '') !== 'https'
            || !is_string($parts['host'] ?? null) || $parts['host'] === '') {
            throw new InvalidArgumentException('HTTP/2 outbound client requires a secure absolute HTTPS origin.');
        }
        if ($request->protocol->negotiate('h2', true) !== ProtocolVersion::HTTP_2) {
            throw new InvalidArgumentException('HTTP/2 outbound protocol policy rejected ALPN h2.');
        }
        $host = trim($parts['host'], '[]');
        $port = $parts['port'] ?? 443;
        $options = self::verifiedOptions($host, $options ?? new DialOptions());

        return $dialer->connect(
            'tcp://' . $parts['host'] . ':' . $port,
            static function (?Connection $connection, ?Throwable $error) use (
                $loop,
                $request,
                $onHeaders,
                $onBody,
                $onComplete,
            ): void {
                if ($connection === null || $error !== null) {
                    $onComplete(null, $error ?? new RuntimeException('HTTP/2 outgoing dial did not yield a connection.'));

                    return;
                }
                try {
                    $exchange = new Http2ConnectedExchange(
                        $loop,
                        $connection,
                        $request,
                        $onHeaders,
                        $onBody,
                        $onComplete,
                    );
                    $exchange->start();
                } catch (Throwable $failure) {
                    $connection->abort();
                    $onComplete(null, $failure);
                }
            },
            $options,
        );
    }

    private static function verifiedOptions(string $host, DialOptions $options): DialOptions
    {
        $tls = $options->tls ?? new TlsClientOptions($host, alpnProtocols: ['h2']);
        if (strcasecmp($tls->peerName, $host) !== 0 || $tls->alpnProtocols !== ['h2']) {
            throw new InvalidArgumentException('HTTP/2 TLS must verify the exact requested origin and h2-only ALPN.');
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
