<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Network;

use Infocyph\Runwire\Loop\LoopInterface;
use InvalidArgumentException;
use Throwable;

/**
 * Bounded full-duplex relay between two exclusively owned stream connections.
 *
 * Each direction forwards at most one tick budget without copying the whole
 * inbound buffer. Downstream write pressure suspends upstream transport reads.
 * EOF currently terminates both sides; half-close and graceful drain belong
 * to the separate U10.08 lifecycle acceptance gate.
 */
final class StreamRelay
{
    private bool $closed = false;

    private bool $forwardingLeft = false;

    private bool $forwardingRight = false;

    private bool $started = false;

    private int $toLeftBytes = 0;

    private int $toRightBytes = 0;

    /** Validate exclusive endpoints and bounded per-tick forwarding budgets. */
    public function __construct(
        private readonly LoopInterface $loop,
        private readonly Connection $left,
        private readonly Connection $right,
        private readonly int $chunkBytes = 16_384,
        private readonly int $maxBytesPerTick = 65_536,
    ) {
        if ($left === $right || $chunkBytes < 1 || $chunkBytes > 65_536
            || $maxBytesPerTick < $chunkBytes || $maxBytesPerTick > 1_048_576) {
            throw new InvalidArgumentException('Invalid bounded stream relay endpoints or forwarding limits.');
        }
    }

    /** Abort both connections and release their reactor handles exactly once. */
    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        $this->left->abort();
        $this->right->abort();
    }

    /** Whether both relay directions have been terminated. */
    public function isClosed(): bool
    {
        return $this->closed;
    }

    /** Take ownership of both established connections and begin forwarding. */
    public function start(): void
    {
        if ($this->started || $this->closed) {
            throw new InvalidArgumentException('Stream relay can only be started once while open.');
        }
        $this->started = true;
        try {
            $this->left->handoffCallbacks(
                $this,
                function (): void { $this->forward(true); },
                function (): void { $this->resume(false); },
                function (): void { $this->close(); },
            );
            $this->right->handoffCallbacks(
                $this,
                function (): void { $this->forward(false); },
                function (): void { $this->resume(true); },
                function (): void { $this->close(); },
            );
            $this->left->onClose(function (): void { $this->close(); });
            $this->right->onClose(function (): void { $this->close(); });
        } catch (Throwable $error) {
            $this->close();

            throw $error;
        }
    }

    /** @return array{toLeft:int,toRight:int} Exact accepted relay bytes per direction. */
    public function transferredBytes(): array
    {
        return ['toLeft' => $this->toLeftBytes, 'toRight' => $this->toRightBytes];
    }

    private function forward(bool $fromLeft): void
    {
        if ($this->closed || ($fromLeft ? $this->forwardingLeft : $this->forwardingRight)) {
            return;
        }
        if ($fromLeft) {
            $this->forwardingLeft = true;
        } else {
            $this->forwardingRight = true;
        }
        try {
            $this->transfer($fromLeft);
        } finally {
            if ($fromLeft) {
                $this->forwardingLeft = false;
            } else {
                $this->forwardingRight = false;
            }
        }
    }

    private function resume(bool $fromLeft): void
    {
        if ($this->closed) {
            return;
        }
        $source = $fromLeft ? $this->left : $this->right;
        $source->resumeReads();
        $this->forward($fromLeft);
    }

    private function transfer(bool $fromLeft): void
    {
        $source = $fromLeft ? $this->left : $this->right;
        $destination = $fromLeft ? $this->right : $this->left;
        $remaining = $this->maxBytesPerTick;

        while (!$this->closed && $source->receivedBytes() > 0 && $remaining > 0) {
            $available = $destination->availableWriteBytes();
            if ($available === 0) {
                break;
            }
            $bytes = $source->read(min($available, $remaining, $this->chunkBytes));
            if ($bytes === '') {
                break;
            }
            if (!$destination->write($bytes)->accepted()) {
                $this->close();

                return;
            }
            $length = strlen($bytes);
            $remaining -= $length;
            if ($fromLeft) {
                $this->toRightBytes += $length;
            } else {
                $this->toLeftBytes += $length;
            }
        }
        if ($destination->isWritePressured() || $destination->availableWriteBytes() === 0) {
            $source->pauseReads();
        } elseif ($source->receivedBytes() > 0) {
            $this->loop->defer(function () use ($fromLeft): void {
                $this->forward($fromLeft);
            });
        }
    }
}
