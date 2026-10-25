<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Network;

use Infocyph\Runwire\Loop\LoopInterface;
use Infocyph\Runwire\Network\Enum\DialAttemptPhase;
use Infocyph\Runwire\Network\Internal\ByteBudget;
use Infocyph\Runwire\Network\Internal\DialAttemptLifecycle;
use Infocyph\Runwire\Network\Internal\EstablishedStreamMetadata;
use Infocyph\Runwire\Network\Internal\TcpSocketTuner;
use LogicException;
use Throwable;

/**
 * Adopts verified accepted or dialed streams into one established I/O engine.
 *
 * A pending dial attempt retains ownership until successful construction.
 */
final class ConnectionFactory
{
    /**
     * Adopt a server-accepted stream with explicit transport metadata.
     *
     * @param resource $stream
     */
    public static function accepted(
        LoopInterface $loop,
        mixed $stream,
        ConnectionLimits $limits = new ConnectionLimits(),
        ?string $peerAddress = null,
        ?string $localAddress = null,
        ?string $negotiatedProtocol = null,
        bool $encrypted = false,
        ?ByteBudget $bufferBudget = null,
        bool $tcpTransport = false,
        ?bool $tcpNoDelayOverride = null,
        bool $strictTlsPeerEof = false,
        ?string $verifiedOutboundTlsPeerName = null,
    ): Connection {
        TcpSocketTuner::applyExplicitNoDelay($stream, $tcpTransport, $tcpNoDelayOverride);
        $metadata = EstablishedStreamMetadata::capture(
            $stream,
            $peerAddress,
            $localAddress,
            $negotiatedProtocol,
            $encrypted,
        );

        return new Connection(
            $loop,
            $stream,
            $limits,
            $metadata['peer'],
            $metadata['local'],
            $metadata['protocol'],
            $metadata['encrypted'],
            $bufferBudget,
            $tcpTransport,
            $tcpNoDelayOverride,
            $metadata['fingerprint'],
            $strictTlsPeerEof,
            $verifiedOutboundTlsPeerName,
        );
    }

    /**
     * Adopt exactly one fully connected and verified outbound stream.
     *
     * @param resource $stream
     */
    public static function dialed(
        DialAttemptLifecycle $attempt,
        LoopInterface $loop,
        mixed $stream,
        ConnectionLimits $limits = new ConnectionLimits(),
        ?string $peerAddress = null,
        ?string $localAddress = null,
        ?string $negotiatedProtocol = null,
        bool $encrypted = false,
        ?ByteBudget $bufferBudget = null,
        bool $tcpTransport = false,
        ?bool $tcpNoDelayOverride = null,
        bool $strictTlsPeerEof = false,
        ?string $verifiedOutboundTlsPeerName = null,
    ): Connection {
        if (!$attempt->readyForHandoff()) {
            throw new LogicException('Only a verified, ready dial attempt can transfer socket ownership.');
        }

        try {
            $connection = self::accepted(
                $loop,
                $stream,
                $limits,
                $peerAddress,
                $localAddress,
                $negotiatedProtocol,
                $encrypted,
                $bufferBudget,
                $tcpTransport,
                $tcpNoDelayOverride,
                $strictTlsPeerEof,
                $verifiedOutboundTlsPeerName,
            );
        } catch (Throwable $error) {
            $attempt->transition(DialAttemptPhase::FAILED);
            if (is_resource($stream)) {
                fclose($stream);
            }

            throw $error;
        }

        $attempt->transition(DialAttemptPhase::HANDED_OFF);

        return $connection;
    }
}
