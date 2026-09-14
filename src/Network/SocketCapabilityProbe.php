<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Network;

/**
 * Probes runtime support for optional socket capabilities used by listeners.
 */
final class SocketCapabilityProbe
{
    /**
     * Reports whether SO_REUSEPORT can be configured by the runtime.
     */
    public static function supportsReusePort(): bool
    {
        if (!function_exists('socket_create')
            || !function_exists('socket_set_option')
            || !function_exists('socket_close')
            || !defined('SO_REUSEPORT')) {
            return false;
        }

        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if ($socket === false) {
            return false;
        }

        try {
            return socket_set_option($socket, SOL_SOCKET, SO_REUSEPORT, 1);
        } finally {
            socket_close($socket);
        }
    }

    /**
     * Reports whether Unix-domain stream sockets are available.
     */
    public static function supportsUnixSockets(): bool
    {
        return in_array('unix', stream_get_transports(), true);
    }
}
