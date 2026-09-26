<?php

declare(strict_types=1);

namespace Infocyph\Runwire\WebSocket\Internal;

use RuntimeException;

/**
 * Represents a WebSocket protocol failure and its RFC close code.
 *
 * @internal
 */
final class WebSocketProtocolException extends RuntimeException
{
    /**
     * Create a protocol failure carrying the RFC 6455 close code.
     */
    public function __construct(
        public readonly int $closeCode,
        string $message,
    ) {
        parent::__construct($message);
    }
}
