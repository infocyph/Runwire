<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Network;

use Infocyph\Runwire\CancellationSubscription;
use Infocyph\Runwire\CancellationToken;
use Infocyph\Runwire\Loop\LoopInterface;
use Infocyph\Runwire\Network\Enum\ConnectionState;
use Infocyph\Runwire\RequestDeadline;
use InvalidArgumentException;
use Throwable;

/**
 * Bounded full-duplex relay between two exclusively owned stream connections.
 *
 * Each direction forwards at most one tick budget without copying the whole
 * inbound buffer. Downstream write pressure suspends upstream transport reads.
 * Cleartext TCP/Unix peers independently propagate write FIN once their
 * forwarded data drains; TLS cannot use raw TCP half-close.
 */
final class StreamRelay
{
    private bool $closed = false;

    private ?int $deadlineTimer = null;

    private bool $forwardingLeft = false;

    private bool $forwardingRight = false;

    private bool $leftEofForwarded = false;

    private bool $rightEofForwarded = false;

    private bool $started = false;

    private ?CancellationSubscription $subscription = null;

    private int $toLeftBytes = 0;

    private int $toRightBytes = 0;

    /** Validate exclusive endpoints and bounded per-tick forwarding budgets. */
    public function __construct(
        private readonly LoopInterface $loop,
        private readonly Connection $left,
        private readonly Connection $right,
        private readonly int $chunkBytes = 16_384,
        private readonly int $maxBytesPerTick = 65_536,
        private readonly ?CancellationToken $cancellation = null,
        private readonly ?RequestDeadline $deadline = null,
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
        $this->subscription?->unsubscribe();
        $this->subscription = null;
        if ($this->deadlineTimer !== null) {
            $this->loop->cancel($this->deadlineTimer);
            $this->deadlineTimer = null;
        }
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
        if (!$this->armLifecycle()) {
            return;
        }

        try {
            if (!$this->left->isEncrypted() && !$this->right->isEncrypted()) {
                $this->left->preservePeerEof();
                $this->right->preservePeerEof();
            }
            $this->left->handoffCallbacks(
                $this,
                function (): void {
                    $this->forward(true);
                },
                function (): void {
                    $this->resume(false);
                },
                function (): void {
                    $this->onEof(true);
                },
            );
            $this->right->handoffCallbacks(
                $this,
                function (): void {
                    $this->forward(false);
                },
                function (): void {
                    $this->resume(true);
                },
                function (): void {
                    $this->onEof(false);
                },
            );
            $this->left->onClose(function (): void {
                $this->close();
            });
            $this->right->onClose(function (): void {
                $this->close();
            });
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

    private function armLifecycle(): bool
    {
        if ($this->cancellation?->isCancelled()) {
            $this->close();

            return false;
        }

        $timeout = min(
            $this->deadline?->remainingSeconds() ?? INF,
            $this->cancellation?->deadline()->remainingSeconds() ?? INF,
        );
        if ($timeout <= 0) {
            $this->close();

            return false;
        }
        if (is_finite($timeout)) {
            $this->deadlineTimer = $this->loop->delay($timeout, function (): void {
                $this->close();
            });
        }

        $subscription = $this->cancellation?->onCancel(function (): void {
            $this->close();
        });
        if ($this->closed) {
            $subscription?->unsubscribe();

            return false;
        }
        $this->subscription = $subscription;

        return true;
    }

    private function completeDirection(bool $fromLeft): void
    {
        if ($this->closed || ($fromLeft ? $this->leftEofForwarded : $this->rightEofForwarded)) {
            return;
        }
        $source = $fromLeft ? $this->left : $this->right;
        if (!$source->peerReadClosed() || $source->receivedBytes() > 0) {
            return;
        }
        if ($fromLeft) {
            $this->leftEofForwarded = true;
        } else {
            $this->rightEofForwarded = true;
        }
        $destination = $fromLeft ? $this->right : $this->left;
        $destination->shutdownWriteAfterDrain(function (): void {
            $this->finishIfFullyDrained();
        });
        $this->finishIfFullyDrained();
    }

    private function finishIfFullyDrained(): void
    {
        if ($this->left->peerReadClosed() && $this->right->peerReadClosed()
            && $this->left->state() === ConnectionState::HALF_CLOSED
            && $this->right->state() === ConnectionState::HALF_CLOSED) {
            $this->close();
        }
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

    private function manageReadPressure(Connection $source, Connection $destination, bool $fromLeft): void
    {
        if ($source->peerReadClosed() && $source->receivedBytes() === 0) {
            $this->completeDirection($fromLeft);

            return;
        }
        if ($destination->isWritePressured() || $destination->availableWriteBytes() === 0) {
            $source->pauseReads();

            return;
        }
        if ($source->receivedBytes() > 0) {
            $this->loop->defer(function () use ($fromLeft): void {
                $this->forward($fromLeft);
            });
        }
    }

    private function moveChunk(Connection $source, Connection $destination, bool $fromLeft, int $remaining): int
    {
        $available = min($destination->availableWriteBytes(), $remaining, $this->chunkBytes);
        if ($available === 0) {
            return 0;
        }
        $bytes = $source->read($available);
        if ($bytes === '') {
            return 0;
        }
        if (!$destination->write($bytes)->accepted()) {
            $this->close();

            return 0;
        }
        $length = strlen($bytes);
        if ($fromLeft) {
            $this->toRightBytes += $length;
        } else {
            $this->toLeftBytes += $length;
        }

        return $length;
    }

    private function onEof(bool $fromLeft): void
    {
        if ($this->closed) {
            return;
        }
        if ($this->left->isEncrypted() || $this->right->isEncrypted()) {
            // Encrypted peers require authenticated close_notify, not raw FIN.
            $this->close();

            return;
        }
        $this->forward($fromLeft);
        $this->completeDirection($fromLeft);
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
            $moved = $this->moveChunk($source, $destination, $fromLeft, $remaining);
            if ($moved === 0) {
                break;
            }
            $remaining -= $moved;
        }
        if (!$this->closed) {
            $this->manageReadPressure($source, $destination, $fromLeft);
        }
    }
}
