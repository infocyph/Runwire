<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Http3\Internal;

/**
 * Adapts QUIC poll timing for idle listeners and pending handshakes.
 *
 * @internal
 */
final class AdaptivePollStrategy
{
    private const float HANDSHAKE_POLL_SECONDS = 0.01;

    private const float MAX_IDLE_POLL_SECONDS = 1.0;

    /**
     * Return a timeout that preserves non-blocking calls and readiness-driven active traffic.
     */
    public static function timeout(
        ?float $baseTimeoutSeconds,
        bool $accepting,
        int $activeConnections,
        int $pendingConnections,
    ): ?float {
        if ($baseTimeoutSeconds === null || $baseTimeoutSeconds <= 0.0) {
            return $baseTimeoutSeconds;
        }
        if ($pendingConnections > 0) {
            return min($baseTimeoutSeconds, self::HANDSHAKE_POLL_SECONDS);
        }
        if ($accepting && $activeConnections === 0) {
            return min(self::MAX_IDLE_POLL_SECONDS, $baseTimeoutSeconds * 4);
        }

        return $baseTimeoutSeconds;
    }
}
