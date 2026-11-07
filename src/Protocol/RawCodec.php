<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Protocol;

use InvalidArgumentException;

/**
 * Treats each non-empty incoming byte chunk as a complete frame without transformation.
 */
final class RawCodec implements FrameCodecInterface
{
    /**
     * Returns zero because raw framing never buffers partial data.
     */
    #[\Override]
    public function bufferedBytes(): int
    {
        return 0;
    }

    /**
     * Returns the frame unchanged for transport.
     */
    #[\Override]
    public function encode(string $frame): string
    {
        return $frame;
    }

    /**
     * Returns a non-empty incoming byte chunk as a single frame.
     */
    #[\Override]
    public function push(string $bytes, int $maxFrames = 256): array
    {
        if ($maxFrames <= 0) {
            throw new InvalidArgumentException('Maximum frames per decode must be positive.');
        }

        return $bytes === '' ? [] : [$bytes];
    }

    /**
     * Resets codec state; raw framing has no buffered state to clear.
     */
    #[\Override]
    public function reset(): void {}
}
