<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Network\Internal;

use Infocyph\Runwire\Network\Enum\CloseReason;
use Infocyph\Runwire\Network\Enum\ConnectionState;

/** @internal State transition and resource cleanup mechanics for established streams. */
trait ConnectionLifecycleOperationsTrait
{
    /**
     * Half-close the local write direction while keeping inbound reads active.
     *
     * Pending writes must be drained explicitly before half-closing.
     */
    public function shutdownWrite(): void
    {
        if ($this->state !== ConnectionState::OPEN) {
            throw new \LogicException('Only open connections can half-close the local write direction.');
        }
        if (!$this->sendBuffer->isEmpty()) {
            throw new \LogicException('Pending writes must drain before half-closing the local write direction.');
        }
        if (!is_resource($this->stream) || !stream_socket_shutdown($this->stream, STREAM_SHUT_WR)) {
            $this->finalize(CloseReason::WRITE_ERROR);

            return;
        }

        $this->state = ConnectionState::HALF_CLOSED;
    }

    private function beginDrain(CloseReason $reason): void
    {
        if ($this->state !== ConnectionState::OPEN && $this->state !== ConnectionState::HALF_CLOSED) {
            return;
        }

        $this->state = ConnectionState::DRAINING;
        $this->drainReason = $reason;
        $this->manualReadPause = true;
        $this->syncReadWatcher();
        if ($this->sendBuffer->isEmpty()) {
            $this->finalize($reason);
        }
    }

    private function finalize(CloseReason $reason): void
    {
        if ($this->state === ConnectionState::CLOSED) {
            return;
        }

        $this->state = ConnectionState::CLOSED;
        $this->closeReason = $reason;
        $readWatcher = $this->readWatcher;
        $writeWatcher = $this->writeWatcher;
        $this->cancelWatcher($readWatcher);
        $this->cancelWatcher($writeWatcher);
        $this->readWatcher = $this->writeWatcher = null;
        $this->timeouts->cancel();

        if (is_resource($this->stream)) {
            fclose($this->stream);
        }
        $this->stream = null;
        $this->receiveBuffer->clear();
        $this->sendBuffer->clear();

        $callbacks = $this->closeCallbacks;
        $this->closeCallbacks = [];
        ConnectionCallbackDispatcher::dispatch($callbacks, $this, $reason);
    }

    private function markPeerEof(): void
    {
        if ($this->state === ConnectionState::CLOSED || $this->peerReadClosed) {
            return;
        }

        $this->peerReadClosed = true;
        $this->syncReadWatcher();
        $this->invoke($this->eofCallback);
        if ($this->state === ConnectionState::OPEN) {
            $this->beginDrain(CloseReason::PEER_CLOSED);
        } elseif ($this->state === ConnectionState::HALF_CLOSED) {
            $this->finalize(CloseReason::PEER_CLOSED);
        }
    }
}
