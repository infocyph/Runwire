<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Network\Internal;

use Infocyph\Runwire\Network\Connection;
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

    /** Return null for plain, accepted or otherwise unverified streams. */
    public static function peerName(Connection $connection): ?string
    {
        return self::$names === null ? null : (self::$names[$connection] ?? null);
    }

    /** Record the peer only after the dialer's secure client TLS handshake succeeded. */
    public static function register(Connection $connection, string $peerName): void
    {
        if (!$connection->isEncrypted() || $peerName === '') {
            throw new LogicException('Verified outbound TLS requires an authenticated encrypted connection.');
        }

        self::$names ??= new WeakMap();
        self::$names[$connection] = $peerName;
    }
}
