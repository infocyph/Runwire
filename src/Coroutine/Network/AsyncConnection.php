<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Coroutine\Network;

use Infocyph\Runwire\Coroutine\CoroutineScope;
use Infocyph\Runwire\Coroutine\Deferred;
use Infocyph\Runwire\Network\Connection;
use Infocyph\Runwire\Network\Enum\CloseReason;
use Infocyph\Runwire\Network\Enum\ConnectionState;
use Infocyph\Runwire\Network\WriteResult;
use InvalidArgumentException;
use LogicException;

final class AsyncConnection
{
    private ?Deferred $closeDeferred = null;

    private bool $closing = false;

    private ?Deferred $drainDeferred = null;

    private bool $draining = false;

    private string $eofBuffer = '';

    private ?Deferred $receiveDeferred = null;

    private int $receiveLimit = PHP_INT_MAX;

    private bool $receiving = false;

    public function __construct(
        private readonly CoroutineScope $scope,
        private readonly Connection $connection,
    ) {
        $connection->claimCallbacks(
            $this,
            fn(Connection $connection) => $this->handleData($connection),
            fn(Connection $connection) => $this->handleDrain($connection),
            fn(Connection $connection) => $this->handleEof($connection),
        );
        $connection->onClose(
            fn(Connection $connection, CloseReason $reason) => $this->handleClose($connection, $reason),
        );
    }

    public function abort(CloseReason $reason = CloseReason::LOCAL_ABORT): void
    {
        $this->connection->abort($reason);
    }

    public function close(): CloseReason
    {
        $reason = $this->connection->closeReason();
        if ($this->connection->state() === ConnectionState::CLOSED && $reason !== null) {
            return $reason;
        }
        if ($this->closing) {
            throw new LogicException('Concurrent AsyncConnection::close() waits are not supported.');
        }

        $this->closing = true;
        $deferred = $this->scope->deferred();
        $this->closeDeferred = $deferred;

        try {
            $this->connection->closeGracefully();
            $reason = $deferred->future()->await();
            if (!$reason instanceof CloseReason) {
                throw new LogicException('Async connection close completed without a close reason.');
            }

            return $reason;
        } finally {
            if ($this->closeDeferred === $deferred) {
                $this->closeDeferred = null;
            }
            $this->closing = false;
        }
    }

    public function closeReason(): ?CloseReason
    {
        return $this->connection->closeReason();
    }

    public function drain(): ?CloseReason
    {
        if (!$this->connection->isWritePressured()) {
            return $this->connection->state() === ConnectionState::CLOSED
                ? $this->connection->closeReason()
                : null;
        }
        if ($this->draining) {
            throw new LogicException('Concurrent AsyncConnection::drain() waits are not supported.');
        }

        $this->draining = true;
        $deferred = $this->scope->deferred();
        $this->drainDeferred = $deferred;

        try {
            $result = $deferred->future()->await();
            if ($result !== null && !$result instanceof CloseReason) {
                throw new LogicException('Async connection drain completed with an invalid result.');
            }

            return $result;
        } finally {
            if ($this->drainDeferred === $deferred) {
                $this->drainDeferred = null;
            }
            $this->draining = false;
        }
    }

    public function receive(int $maxBytes = PHP_INT_MAX): string
    {
        if ($maxBytes < 0) {
            throw new InvalidArgumentException('Maximum receive length cannot be negative.');
        }
        if ($maxBytes === 0) {
            return '';
        }
        if ($this->eofBuffer !== '') {
            return $this->readEofBuffer($maxBytes);
        }
        if ($this->connection->receivedBytes() > 0) {
            return $this->connection->read($maxBytes);
        }
        if ($this->connection->peerReadClosed() || $this->connection->state() === ConnectionState::CLOSED) {
            return '';
        }
        if ($this->receiving) {
            throw new LogicException('Concurrent AsyncConnection::receive() waits are not supported.');
        }

        $this->receiving = true;
        $this->receiveLimit = $maxBytes;
        $deferred = $this->scope->deferred();
        $this->receiveDeferred = $deferred;

        try {
            return (string) $deferred->future()->await();
        } finally {
            if ($this->receiveDeferred === $deferred) {
                $this->receiveDeferred = null;
            }
            $this->receiveLimit = PHP_INT_MAX;
            $this->receiving = false;
        }
    }

    public function state(): ConnectionState
    {
        return $this->connection->state();
    }

    public function write(string $data): WriteResult
    {
        return $this->connection->write($data);
    }

    private function handleClose(Connection $connection, CloseReason $reason): void
    {
        $this->resolveReceive('');
        $this->resolveDrain($reason);

        $deferred = $this->closeDeferred;
        $this->closeDeferred = null;
        $deferred?->resolve($reason);

        $connection->releaseCallbacks($this);
    }

    private function handleData(Connection $connection): void
    {
        if ($this->receiveDeferred === null) {
            return;
        }

        $data = $connection->read($this->receiveLimit);
        if ($data !== '') {
            $this->resolveReceive($data);
        }
    }

    private function handleDrain(Connection $connection): void
    {
        unset($connection);
        $this->resolveDrain(null);
    }

    private function handleEof(Connection $connection): void
    {
        $remaining = $connection->read();
        if ($remaining !== '') {
            if ($this->receiveDeferred !== null) {
                [$data, $rest] = $this->splitReceive($remaining, $this->receiveLimit);
                $this->eofBuffer .= $rest;
                $this->resolveReceive($data);

                return;
            }

            $this->eofBuffer .= $remaining;
        }

        $this->resolveReceive('');
    }

    private function readEofBuffer(int $maxBytes): string
    {
        [$data, $rest] = $this->splitReceive($this->eofBuffer, $maxBytes);
        $this->eofBuffer = $rest;

        return $data;
    }

    private function resolveDrain(?CloseReason $reason): void
    {
        $deferred = $this->drainDeferred;
        if ($deferred === null) {
            return;
        }

        $this->drainDeferred = null;
        $deferred->resolve($reason);
    }

    private function resolveReceive(string $data): void
    {
        $deferred = $this->receiveDeferred;
        if ($deferred === null) {
            return;
        }

        $this->receiveDeferred = null;
        $deferred->resolve($data);
    }

    /** @return array{string, string} */
    private function splitReceive(string $data, int $maxBytes): array
    {
        if ($maxBytes >= strlen($data)) {
            return [$data, ''];
        }

        return [substr($data, 0, $maxBytes), substr($data, $maxBytes)];
    }
}
