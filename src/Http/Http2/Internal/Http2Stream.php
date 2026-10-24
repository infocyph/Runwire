<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Http2\Internal;

use Closure;
use Infocyph\Runwire\Http\Http2\Enum\StreamState;
use Infocyph\Runwire\Http\Http2\Http2ResponseWriter;
use Infocyph\Runwire\Http\Internal\StreamingRequestBody;
use Infocyph\Runwire\Network\Internal\ByteBudget;
use Infocyph\Runwire\Network\Internal\ByteQueue;

/**
 * Stores mutable state for one HTTP/2 stream.
 */
final class Http2Stream
{
    public readonly ByteQueue $outbound;

    public ?int $declaredContentLength = null;

    public bool $discardInbound = false;

    public bool $dispatching = false;

    public ?Closure $drainCallback = null;

    public bool $endPending = false;

    public bool $headersReceived = false;

    public ?int $idleTimer = null;

    public int $receivedBodyBytes = 0;

    public StreamState $state = StreamState::IDLE;

    public bool $trailersReceived = false;

    public bool $writePressured = false;

    public ?Http2ResponseWriter $writer = null;

    /**
     * Create stream state with its request body and flow-control windows.
     */
    public function __construct(
        public readonly int $id,
        public readonly StreamingRequestBody $body,
        public int $sendWindow,
        public int $receiveWindow,
        ?ByteBudget $budget = null,
    ) {
        $this->outbound = new ByteQueue($budget);
    }

    /**
     * Mark the local side of the stream ended.
     */
    public function localEnd(): void
    {
        $this->state = match ($this->state) {
            StreamState::OPEN => StreamState::HALF_CLOSED_LOCAL,
            StreamState::HALF_CLOSED_REMOTE => StreamState::CLOSED,
            default => $this->state,
        };
    }

    /**
     * Determine whether the local side can still send data.
     */
    public function localOpen(): bool
    {
        return $this->state === StreamState::OPEN || $this->state === StreamState::HALF_CLOSED_REMOTE;
    }

    /**
     * Open the stream and optionally mark the remote side ended.
     */
    public function open(bool $remoteEnded): void
    {
        $this->state = $remoteEnded ? StreamState::HALF_CLOSED_REMOTE : StreamState::OPEN;
        $this->headersReceived = true;
    }

    /**
     * Mark the remote side of the stream ended.
     */
    public function remoteEnd(): void
    {
        $this->state = match ($this->state) {
            StreamState::OPEN => StreamState::HALF_CLOSED_REMOTE,
            StreamState::HALF_CLOSED_LOCAL => StreamState::CLOSED,
            default => $this->state,
        };
    }

    /**
     * Determine whether the remote side can still send data.
     */
    public function remoteOpen(): bool
    {
        return $this->state === StreamState::OPEN || $this->state === StreamState::HALF_CLOSED_LOCAL;
    }

    /**
     * Reset and close the stream, discarding pending output and request data.
     */
    public function reset(): void
    {
        $this->state = StreamState::CLOSED;
        $this->outbound->clear();
        $this->endPending = false;
        $this->body->cancel();
    }
}
