<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Http2\Internal;

use Infocyph\Runwire\Http\Http2\Enum\ErrorCode;
use Infocyph\Runwire\Http\Http2\Enum\FrameType;
use Infocyph\Runwire\Loop\LoopInterface;

final class ControlFrameBudget
{
    private int $count = 0;

    private float $windowStartedAt;

    public function __construct(
        private readonly LoopInterface $loop,
        private readonly int $maxPerSecond,
    ) {
        $this->windowStartedAt = $loop->now();
    }

    public function consume(?FrameType $type): void
    {
        if ($type === null || !in_array($type, [
            FrameType::SETTINGS,
            FrameType::PING,
            FrameType::RST_STREAM,
            FrameType::WINDOW_UPDATE,
        ], true)) {
            return;
        }

        $now = $this->loop->now();
        if ($now - $this->windowStartedAt >= 1.0) {
            $this->windowStartedAt = $now;
            $this->count = 0;
        }
        if (++$this->count > $this->maxPerSecond) {
            throw new ConnectionError(ErrorCode::ENHANCE_YOUR_CALM, 'HTTP/2 control-frame work budget exceeded.');
        }
    }
}
