<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Protocol;

interface FrameCodecInterface
{
    /** @return list<string> */
    public function push(string $bytes, int $maxFrames = 256): array;

    public function encode(string $frame): string;

    public function bufferedBytes(): int;

    public function reset(): void;
}
