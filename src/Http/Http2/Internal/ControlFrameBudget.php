<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Http2\Internal;

use Infocyph\Runwire\Http\Http2\Enum\ErrorCode;
use Infocyph\Runwire\Http\Http2\Enum\FrameType;
use Infocyph\Runwire\Loop\LoopInterface;

/**
 * Enforces a per-second budget for inbound HTTP/2 control frames.
 */
final class ControlFrameBudget
{
    private int $count = 0;

    private int $earnedWindowUpdates = 0;

    private float $windowStartedAt;

    /**
     * Create a control-frame budget bound to the event-loop clock.
     */
    public function __construct(
        private readonly LoopInterface $loop,
        private readonly int $maxPerSecond,
    ) {
        $this->windowStartedAt = $loop->now();
    }

    /**
     * Credit inbound WINDOW_UPDATE work only after actually accepting outbound
     * DATA. A high-rate legitimate peer can replenish flow windows without
     * weakening the independent SETTINGS/PING/RST_STREAM flood ceiling.
     */
    public function creditOutboundData(int $bytes): void
    {
        if ($bytes <= 0) {
            return;
        }

        $this->resetWindowIfNeeded();
        $limit = min($this->maxPerSecond, 100_000) * 8;
        $this->earnedWindowUpdates = min(
            $limit,
            $this->earnedWindowUpdates + intdiv($bytes + 8_191, 8_192),
        );
    }

    /**
     * Consume budget for a recognized control frame.
     */
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

        $this->resetWindowIfNeeded();
        if (++$this->count <= $this->maxPerSecond) {
            return;
        }
        if ($type === FrameType::WINDOW_UPDATE && $this->earnedWindowUpdates > 0) {
            --$this->earnedWindowUpdates;

            return;
        }

        throw new ConnectionError(ErrorCode::ENHANCE_YOUR_CALM, 'HTTP/2 control-frame work budget exceeded.');
    }

    private function resetWindowIfNeeded(): void
    {
        $now = $this->loop->now();
        if ($now - $this->windowStartedAt >= 1.0) {
            $this->windowStartedAt = $now;
            $this->count = 0;
            $this->earnedWindowUpdates = 0;
        }
    }
}
