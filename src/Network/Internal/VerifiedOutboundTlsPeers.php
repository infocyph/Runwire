<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Network\Internal;

use Infocyph\Runwire\Network\Connection;
use InvalidArgumentException;
use LogicException;
use WeakMap;

/**
 * @internal
 * Only the verified outbound TLS dialer records an authenticated peer.
 * Weak keys retire identity when a connection goes out of scope.
 */
final class VerifiedOutboundTlsPeers
{
    /** @var WeakMap<Connection, string>|null */
    private static ?WeakMap $names = null;

    /**
     * Never relabel a Runwire-authenticated connection as another HTTPS host.
     * Externally adopted transports retain their explicit caller-verification contract.
     * @throws \InvalidArgumentException
     */
    public static function assertOrigin(Connection $connection, string $uri): void
    {
        $peer = self::peerName($connection);
        if ($peer === null) {
            return;
        }
        $host = parse_url($uri, PHP_URL_HOST);
        if (!is_string($host) || strcasecmp($peer, trim($host, '[]')) !== 0) {
            throw new InvalidArgumentException('Outbound HTTPS origin differs from the authenticated TLS peer.');
        }
    }

    /** Return null for plain, accepted or otherwise unverified streams. */
    public static function peerName(Connection $connection): ?string
    {
        return self::$names === null ? null : (self::$names[$connection] ?? null);
    }

    /**
     * Record the peer only after the dialer's secure client TLS handshake succeeded.
     * @throws \LogicException
     */
    public static function register(Connection $connection, string $peerName): void
    {
        if (!$connection->isEncrypted() || $peerName === '') {
            throw new LogicException('Verified outbound TLS requires an authenticated encrypted connection.');
        }

        if (self::$names === null) {
            /** @var WeakMap<Connection, string> $names */
            $names = new WeakMap();
            self::$names = $names;
        }
        self::$names[$connection] = $peerName;
    }
}
