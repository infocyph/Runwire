<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Protocol;

/**
 * Encodes application frames and incrementally decodes framed byte streams.
 */
interface FrameCodecInterface
{
    /**
     * Returns bytes currently buffered awaiting a complete frame.
     */
    public function bufferedBytes(): int;

    /**
     * Encodes one application frame for transport.
     */
    public function encode(string $frame): string;

    /** @return list<string> */
    public function push(string $bytes, int $maxFrames = 256): array;

    /**
     * Clears any buffered decoder state.
     */
    public function reset(): void;
}
