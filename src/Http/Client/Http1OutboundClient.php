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
 * Experimental nonblocking HTTP/1.1 request dial and single exchange.
 *
 * Uses only the shared verified Happy Eyeballs/Dialer/Connection runtime.
 * This is not an HTTP/2, HTTP/3, redirecting or actively pooled client.
 */
final readonly class Http1OutboundClient
{
    /**
     * Start one outbound HTTP/1.1 connection and stream response callbacks.
     *
     * @param callable(int, \Infocyph\Runwire\Http\Headers):void $onHeaders
     * @param callable(string):void $onBody
     * @param callable(?Connection, ?Throwable):void $completion
     */
    public static function connect(
        LoopInterface $loop,
        HappyEyeballsDialer $dialer,
        ClientRequest $request,
        callable $onHeaders,
        callable $onBody,
        callable $completion,
        ?DialOptions $dialOptions = null,
    ): int {
        $parts = parse_url($request->uri);
        if (!is_array($parts) || !is_string($parts['host'] ?? null)) {
            throw new InvalidArgumentException('HTTP/1.1 client requires a valid absolute origin.');
        }
        $host = $parts['host'];
        $secure = strtolower($parts['scheme'] ?? '') === 'https';
        // Validate the exact intended H1 transport before doing DNS, socket
        // admission or TLS handshakes. H2/H3-only policy must never silently
        // become H1 merely because this particular client is H1-only.
        if ($request->protocol->negotiate($secure ? 'http/1.1' : null, $secure)
            !== ProtocolVersion::HTTP_1_1) {
            throw new InvalidArgumentException('HTTP/1.1 outbound policy must explicitly allow HTTP/1.1.');
        }
        $port = $parts['port'] ?? ($secure ? 443 : 80);
        $dialOptions = self::verifiedOptions($host, $secure, $dialOptions ?? new DialOptions());
        $endpoint = 'tcp://' . $host . ':' . $port;

        return $dialer->connect(
            $endpoint,
            static function (?Connection $connection, ?Throwable $error) use (
                $loop,
                $request,
                $onHeaders,
                $onBody,
                $completion,
            ): void {
                if ($connection === null || $error !== null) {
                    $completion(null, $error ?? new RuntimeException('HTTP/1.1 connection failed to establish.'));

                    return;
                }

                try {
                    $exchange = new Http1ConnectedExchange(
                        $loop,
                        $connection,
                        $request,
                        $onHeaders,
                        $onBody,
                        $completion,
                    );
                    $exchange->start();
                } catch (Throwable $error) {
                    $connection->abort();
                    $completion(null, $error);
                }
            },
            $dialOptions,
        );
    }

    private static function verifiedOptions(string $host, bool $secure, DialOptions $options): DialOptions
    {
        $tls = $options->tls;
        if (!$secure) {
            if ($tls !== null) {
                throw new InvalidArgumentException('Plaintext HTTP requests cannot carry a TLS policy; use https://.');
            }

            return $options;
        }

        $peerName = trim($host, '[]');
        $tls ??= new TlsClientOptions($peerName, alpnProtocols: ['http/1.1']);
        if (strcasecmp($tls->peerName, $peerName) !== 0 || $tls->alpnProtocols !== ['http/1.1']) {
            throw new InvalidArgumentException('HTTP/1.1 TLS must verify the exact requested origin and HTTP/1.1 ALPN.');
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
