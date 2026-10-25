<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Client;

use Infocyph\Runwire\Exception\RuntimeUnavailableException;
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
 * Experimental TLS-negotiated one-request H1/H2 client with explicit fallback.
 *
 * Uses the same verified stream/dialer as standalone clients. H3 requires an
 * independently implemented QUIC client and is never silently downgraded.
 */
final readonly class HttpNegotiatingOutboundClient
{
    /**
     * Negotiate TLS ALPN before selecting the established HTTP exchange.
     *
     * @param callable(int,\Infocyph\Runwire\Http\Headers):void $onHeaders
     * @param callable(string):void $onBody
     * @param callable(?Connection,?Throwable):void $completion
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
        $policy = $request->protocol;
        if ($policy->preferred === ProtocolVersion::HTTP_3
            || in_array(ProtocolVersion::HTTP_3, $policy->allowedVersions, true)) {
            throw new RuntimeUnavailableException('HTTP/3 cannot fall back before an outbound QUIC client is implemented.');
        }
        $parts = parse_url($request->uri);
        if (!is_array($parts) || !is_string($parts['host'] ?? null)) {
            throw new InvalidArgumentException('HTTP client requires an absolute HTTP origin.');
        }
        $secure = strtolower($parts['scheme'] ?? '') === 'https';
        $options = $dialOptions ?? new DialOptions();
        if (!$secure) {
            if ($policy->preferred !== ProtocolVersion::HTTP_1_1) {
                throw new RuntimeUnavailableException('Plaintext cannot downgrade an H2-preferred client.');
            }

            return Http1OutboundClient::connect(
                $loop,
                $dialer,
                $request,
                $onHeaders,
                $onBody,
                $completion,
                $options,
            );
        }
        $options = self::verifiedOptions($request, $options, trim($parts['host'], '[]'));
        $port = $parts['port'] ?? 443;

        return $dialer->connect(
            'tcp://' . $parts['host'] . ':' . $port,
            static function (?Connection $connection, ?Throwable $error) use (
                $loop,
                $request,
                $onHeaders,
                $onBody,
                $completion,
            ): void {
                if ($connection === null || $error !== null) {
                    $completion(null, $error ?? new RuntimeException('Negotiated HTTP dial did not return a connection.'));

                    return;
                }

                try {
                    self::startExchange(
                        $loop,
                        $connection,
                        $request,
                        $onHeaders,
                        $onBody,
                        $completion,
                    );
                } catch (Throwable $failure) {
                    $connection->abort();
                    $completion(null, $failure);
                }
            },
            $options,
        );
    }

    /**
     * Start exactly one exchanged H1/H2 request after verified TLS negotiation.
     *
     * @param callable(int,\Infocyph\Runwire\Http\Headers):void $onHeaders
     * @param callable(string):void $onBody
     * @param callable(?Connection,?Throwable):void $completion
     */
    private static function startExchange(
        LoopInterface $loop,
        Connection $connection,
        ClientRequest $request,
        callable $onHeaders,
        callable $onBody,
        callable $completion,
    ): void {
        if (!$connection->isEncrypted()) {
            throw new RuntimeUnavailableException('TLS negotiation did not produce an encrypted connection.');
        }
        $protocol = $request->protocol->negotiate($connection->negotiatedProtocol(), true);
        $exchange = match ($protocol) {
            ProtocolVersion::HTTP_1_1 => new Http1ConnectedExchange(
                $loop,
                $connection,
                $request,
                $onHeaders,
                $onBody,
                $completion,
            ),
            ProtocolVersion::HTTP_2 => new Http2ConnectedExchange(
                $loop,
                $connection,
                $request,
                $onHeaders,
                $onBody,
                $completion,
            ),
            default => throw new RuntimeUnavailableException('Unsupported HTTP client version after ALPN.'),
        };
        $exchange->start();
    }

    /** Validate a complete ALPN offer against original peer identity before DNS. */
    private static function verifiedOptions(
        ClientRequest $request,
        DialOptions $options,
        string $peer,
    ): DialOptions {
        $policy = $request->protocol;
        $offered = [$policy->preferred === ProtocolVersion::HTTP_2 ? 'h2' : 'http/1.1'];
        if ($policy->allowFallback) {
            foreach ($policy->allowedVersions as $version) {
                $alpn = $version === ProtocolVersion::HTTP_2 ? 'h2' : 'http/1.1';
                if (!in_array($alpn, $offered, true)) {
                    $offered[] = $alpn;
                }
            }
        }
        if ($request->body !== null && in_array('h2', $offered, true)) {
            throw new RuntimeUnavailableException('Streaming request bodies require an explicitly selected HTTP/1.1 client.');
        }
        $tls = $options->tls ?? new TlsClientOptions($peer, alpnProtocols: $offered);
        if (strcasecmp($tls->peerName, $peer) !== 0 || $tls->alpnProtocols !== $offered) {
            throw new InvalidArgumentException('Negotiating HTTP TLS must preserve the original peer and exact offered ALPN policy.');
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
