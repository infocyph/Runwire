<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Protocol;

use InvalidArgumentException;

final class RawCodec implements FrameCodecInterface
{
    public function push(string $bytes, int $maxFrames = 256): array
    {
        if ($maxFrames <= 0) {
            throw new InvalidArgumentException('Maximum frames per decode must be positive.');
        }
        return $bytes === '' ? [] : [$bytes];
    }

    public function encode(string $frame): string
    {
        return $frame;
    }

    public function bufferedBytes(): int
    {
        return 0;
    }

    public function reset(): void
    {
    }
}
