<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Http2\Internal;

use Closure;
use Infocyph\Runwire\Http\Http2\Http2ResponseWriter;
use Infocyph\Runwire\Http\Http2\StreamState;
use Infocyph\Runwire\Http\Internal\StreamingRequestBody;
use Infocyph\Runwire\Network\Internal\ByteQueue;

final class Http2Stream
{
    public StreamState $state = StreamState::IDLE;
    public int $sendWindow;
    public int $receiveWindow;
    public int $receivedBodyBytes = 0;
    public ?int $declaredContentLength = null;
    public bool $discardInbound = false;
    public bool $endPending = false;
    public bool $headersReceived = false;
    public bool $trailersReceived = false;
    public bool $writePressured = false;
    public bool $dispatching = false;
    public readonly ByteQueue $outbound;
    public ?Http2ResponseWriter $writer = null;
    public ?Closure $drainCallback = null;
    public ?int $idleTimer = null;

    public function __construct(
        public readonly int $id,
        public readonly StreamingRequestBody $body,
        int $sendWindow,
        int $receiveWindow,
    ) {
        $this->sendWindow = $sendWindow;
        $this->receiveWindow = $receiveWindow;
        $this->outbound = new ByteQueue();
    }

    public function open(bool $remoteEnded): void
    {
        $this->state = $remoteEnded ? StreamState::HALF_CLOSED_REMOTE : StreamState::OPEN;
        $this->headersReceived = true;
    }

    public function remoteEnd(): void
    {
        $this->state = match ($this->state) {
            StreamState::OPEN => StreamState::HALF_CLOSED_REMOTE,
            StreamState::HALF_CLOSED_LOCAL => StreamState::CLOSED,
            default => $this->state,
        };
    }

    public function localEnd(): void
    {
        $this->state = match ($this->state) {
            StreamState::OPEN => StreamState::HALF_CLOSED_LOCAL,
            StreamState::HALF_CLOSED_REMOTE => StreamState::CLOSED,
            default => $this->state,
        };
    }

    public function reset(): void
    {
        $this->state = StreamState::CLOSED;
        $this->outbound->clear();
        $this->endPending = false;
        $this->body->cancel();
    }

    public function remoteOpen(): bool
    {
        return $this->state === StreamState::OPEN || $this->state === StreamState::HALF_CLOSED_LOCAL;
    }

    public function localOpen(): bool
    {
        return $this->state === StreamState::OPEN || $this->state === StreamState::HALF_CLOSED_REMOTE;
    }
}
