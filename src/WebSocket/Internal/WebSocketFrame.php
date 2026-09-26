<?php

declare(strict_types=1);

namespace Infocyph\Runwire\WebSocket\Internal;

/**
 * Carries one validated and unmasked WebSocket frame.
 *
 * @internal
 */
final readonly class WebSocketFrame
{
    public function __construct(
        public bool $fin,
        public int $opcode,
        public string $payload,
    ) {}
}
