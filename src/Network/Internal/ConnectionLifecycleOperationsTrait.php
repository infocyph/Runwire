<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Network\Internal;

use Infocyph\Runwire\CancellationToken;
use Infocyph\Runwire\Network\Enum\CloseReason;
use Infocyph\Runwire\Network\Enum\ConnectionState;
use Infocyph\Runwire\Network\Enum\WriteState;
use Infocyph\Runwire\Network\WriteResult;
use Infocyph\Runwire\RequestDeadline;
use LogicException;

/** @internal State transition and resource cleanup mechanics for established streams. */
trait ConnectionLifecycleOperationsTrait
{
    /** Bind this established stream to one owner token and its earliest deadline. */
    public function bindOwner(CancellationToken $token, ?RequestDeadline $deadline = null): void
    {
        if ($this->state === ConnectionState::CLOSED || $this->scopeOwnership !== null) {
            throw new LogicException('Only one live owner may bind an established connection.');
        }

        $this->scopeOwnership = ConnectionScopeOwnership::attach($this, $this->loop, $token, $deadline);
        if ($this->state() === ConnectionState::CLOSED) {
            $this->scopeOwnership->dispose();
            $this->scopeOwnership = null;
        }
    }

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
        if ($this->encrypted) {
            throw new LogicException('Raw TCP half-close cannot represent an authenticated TLS close_notify.');
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

    /**
     * Explicitly rebind a live connection to a different cancellation owner.
     * Existing ownership is preserved if the new token is already cancelled.
     */
    public function transferOwner(CancellationToken $token, ?RequestDeadline $deadline = null): void
    {
        if ($this->state === ConnectionState::CLOSED || $this->scopeOwnership === null) {
            throw new LogicException('Only a live, previously owned connection can be transferred.');
        }

        $token->throwIfCancelled();
        $this->scopeOwnership->dispose();
        $this->scopeOwnership = null;
        $this->bindOwner($token, $deadline);
    }

    /**
     * Write bytes immediately when possible or queue them within configured bounds.
     */
    public function write(string $data): WriteResult
    {
        if ($this->state !== ConnectionState::OPEN) {
            return new WriteResult(WriteState::CLOSED, $this->sendBuffer->bytes());
        }
        if ($data === '') {
            return $this->writeResult();
        }

        $length = strlen($data);
        if ($length > $this->limits->maxSendBufferBytes - $this->sendBuffer->bytes()) {
            ++$this->rejectedWrites;

            return new WriteResult(WriteState::REJECTED_LIMIT, $this->sendBuffer->bytes());
        }

        if ($this->sendBuffer->isEmpty()) {
            $result = $this->writeImmediately($data, $length);
            if ($result !== null) {
                return $result;
            }
        }

        if (strlen($data) > $this->sendBuffer->budgetAvailable()) {
            $this->finalize(CloseReason::WRITE_ERROR);

            return new WriteResult(WriteState::CLOSED, $this->sendBuffer->bytes());
        }

        $this->sendBuffer->append($data);
        $this->syncWriteWatcher();
        $this->updateWritePressure();

        return $this->writeResult();
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
