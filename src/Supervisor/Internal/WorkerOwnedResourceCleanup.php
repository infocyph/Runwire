<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Supervisor\Internal;

use Infocyph\Runwire\Executor\ProcessExecutor;
use Infocyph\Runwire\Network\BoundedConnectionPool;
use Infocyph\Runwire\Network\Connection;
use Infocyph\Runwire\Network\Dialer;
use Infocyph\Runwire\Network\HappyEyeballsDialer;
use Infocyph\Runwire\Network\QuicClientSession;
use Throwable;

/**
 * Retires independent worker-owned I/O without allowing observer exceptions to
 * interrupt the remainder of the shutdown inventory.
 */
final class WorkerOwnedResourceCleanup
{
    /** @param array<int, ProcessExecutor> $executors */
    public static function executors(array $executors): void
    {
        foreach ($executors as $executor) {
            try {
                $executor->close();
            } catch (Throwable) {
                // Independent owned resources must still be retired.
            }
        }
    }

    /**
     * @param array<int, HappyEyeballsDialer> $happyDialers
     * @param array<int, Dialer> $dialers
     * @param array<int, Connection> $connections
     * @param array<int, BoundedConnectionPool> $pools
     * @param array<int, QuicClientSession> $sessions
     */
    public static function network(
        array $happyDialers,
        array $dialers,
        array $connections,
        array $pools,
        array $sessions,
    ): void {
        self::happyDialers($happyDialers);
        self::dialers($dialers);
        self::connections($connections);
        self::pools($pools);
        self::sessions($sessions);
    }

    /** @param array<int, Connection> $items */
    private static function connections(array $items): void
    {
        foreach ($items as $connection) {
            try {
                $connection->abort();
            } catch (Throwable) {
                // Continue retiring other active connections.
            }
        }
    }

    /** @param array<int, Dialer> $items */
    private static function dialers(array $items): void
    {
        foreach ($items as $dialer) {
            try {
                $dialer->close();
            } catch (Throwable) {
                // Continue closing independently owned outgoing sockets.
            }
        }
    }

    /** @param array<int, HappyEyeballsDialer> $items */
    private static function happyDialers(array $items): void
    {
        foreach ($items as $dialer) {
            try {
                $dialer->close();
            } catch (Throwable) {
                // Continue retiring other pending connector races.
            }
        }
    }

    /** @param array<int, BoundedConnectionPool> $items */
    private static function pools(array $items): void
    {
        foreach ($items as $pool) {
            try {
                $pool->close();
            } catch (Throwable) {
                // A user completion observer must not stop sibling pool teardown.
            }
        }
    }

    /** @param array<int, QuicClientSession> $items */
    private static function sessions(array $items): void
    {
        foreach ($items as $session) {
            try {
                $session->dispose();
            } catch (Throwable) {
                // Continue retiring other owned QUIC sessions.
            }
        }
    }
}
