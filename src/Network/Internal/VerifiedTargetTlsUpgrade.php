<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Network\Internal;

use Closure;
use Infocyph\Runwire\Loop\LoopInterface;
use Infocyph\Runwire\Network\Connection;
use Infocyph\Runwire\Network\ConnectionFactory;
use Infocyph\Runwire\Network\ConnectionLimits;
use Infocyph\Runwire\Network\TlsClientOptions;
use LogicException;
use Throwable;

/** @internal Exclusive post-CONNECT original-target TLS handshake ownership. */
final class VerifiedTargetTlsUpgrade
{
    /** Refuse upgrades unless the cleartext tunnel is exclusively quiescent. */
    public static function assertAllowed(Connection $connection, mixed $stream): void
    {
        if ($connection->isEncrypted()) {
            throw new LogicException('TLS-over-TLS proxy nesting is unavailable; original-target verification cannot be guaranteed.');
        }
        if (!$connection->isPoolIdleSafe() || !is_resource($stream)) {
            throw new LogicException('TLS upgrade requires an unowned, quiescent cleartext tunnel.');
        }
    }

    /**
     * The old cleartext Connection has already retired all watchers and given
     * this worker unique ownership of its socket.
     *
     * @param resource $stream
     * @param callable(?Connection, ?Throwable):void $completion
     */
    public static function start(
        LoopInterface $loop,
        mixed $stream,
        TlsClientOptions $policy,
        ConnectionLimits $limits,
        bool $tcpTransport,
        ?bool $tcpNoDelayOverride,
        callable $completion,
    ): void {
        $notify = Closure::fromCallable($completion);

        try {
            ClientTlsHandshake::start(
                $loop,
                $stream,
                $policy,
                function (?string $alpn) use (
                    $loop, $stream, $policy, $limits, $tcpTransport, $tcpNoDelayOverride, $notify,
                ): void {
                    self::verified($loop, $stream, $policy, $limits, $tcpTransport, $tcpNoDelayOverride, $notify, $alpn);
                },
                function (Throwable $error) use ($stream, $notify): void {
                    self::failed($stream, $notify, $error);
                },
            );
        } catch (Throwable $error) {
            self::failed($stream, $notify, $error);
        }
    }

    /**
     * @param resource $stream
     * @param Closure(?Connection, ?Throwable):void $notify
     */
    private static function failed(mixed $stream, Closure $notify, Throwable $error): void
    {
        if (is_resource($stream)) {
            fclose($stream);
        }

        $notify(null, $error);
    }

    /**
     * @param resource $stream
     * @param Closure(?Connection, ?Throwable):void $notify
     */
    private static function verified(
        LoopInterface $loop,
        mixed $stream,
        TlsClientOptions $policy,
        ConnectionLimits $limits,
        bool $tcpTransport,
        ?bool $tcpNoDelayOverride,
        Closure $notify,
        ?string $alpn,
    ): void {
        try {
            $encrypted = ConnectionFactory::accepted(
                $loop,
                $stream,
                $limits,
                negotiatedProtocol: $alpn,
                encrypted: true,
                tcpTransport: $tcpTransport,
                tcpNoDelayOverride: $tcpNoDelayOverride,
                strictTlsPeerEof: true,
            );
            VerifiedOutboundTlsPeers::register($encrypted, $policy->peerName);
        } catch (Throwable $error) {
            self::failed($stream, $notify, $error);

            return;
        }

        // Do not catch consumer exceptions and invoke a second completion.
        $notify($encrypted, null);
    }
}
