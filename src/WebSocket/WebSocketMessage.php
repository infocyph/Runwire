<?php

declare(strict_types=1);

namespace Infocyph\Runwire\WebSocket;

/**
 * Represents one fully reassembled inbound WebSocket message.
 */
final readonly class WebSocketMessage
{
    /**
     * Create a text or binary WebSocket message.
     */
    public function __construct(
        public string $data,
        public bool $binary = false,
    ) {}
}
