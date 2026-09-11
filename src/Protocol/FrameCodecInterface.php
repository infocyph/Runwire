<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Protocol;

interface FrameCodecInterface
{
    public function bufferedBytes(): int;

    public function encode(string $frame): string;

    /** @return list<string> */
    public function push(string $bytes, int $maxFrames = 256): array;

    public function reset(): void;
}
