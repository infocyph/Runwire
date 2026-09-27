<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Network\Internal;

/**
 * Applies optional TCP socket tuning to accepted stream resources.
 *
 * @internal
 */
final class TcpSocketTuner
{
    /** @param resource|null $stream */
    public static function applyNoDelayDefault(
        mixed $stream,
        bool $tcpTransport,
        ?bool $override,
        bool $enabled,
    ): void {
        if (!$tcpTransport || $override !== null || !is_resource($stream) || !self::supported()) {
            return;
        }

        set_error_handler(static fn(int $severity): bool => $severity === E_WARNING);

        try {
            $socket = socket_import_stream($stream);
            if ($socket !== false) {
                socket_set_option($socket, SOL_TCP, TCP_NODELAY, $enabled ? 1 : 0);
            }
        } finally {
            restore_error_handler();
        }
    }

    private static function supported(): bool
    {
        return function_exists('socket_import_stream')
            && function_exists('socket_set_option')
            && defined('SOL_TCP')
            && defined('TCP_NODELAY');
    }
}
