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
use WeakReference;

/**
 * Provides coroutine-friendly waiting around a network connection.
 */
final class AsyncConnection
{
    private readonly Connection $connection;

    private readonly CoroutineScope $scope;

    private bool $attached;

    private ?Deferred $closeDeferred = null;

    private bool $closing = false;

    private ?Deferred $drainDeferred = null;

    private bool $draining = false;

    private bool $disposed = false;

    private string $eofBuffer = '';

    private ?Deferred $receiveDeferred = null;

    private int $receiveLimit = PHP_INT_MAX;

    private bool $receiving = false;

    /**
     * Bind coroutine waits to the supplied connection.
     */
    public function __construct(CoroutineScope $scope, Connection $connection)
    {
        $this->connection = $connection;
        $this->scope = $scope;
        $weakSelf = WeakReference::create($this);
        $connection->claimCallbacks(
            $this,
            static function (Connection $connection) use ($weakSelf): void {
                $adapter = $weakSelf->get();
                if ($adapter instanceof self) {
                    $adapter->handleData($connection);
                }
            },
            static function (Connection $connection) use ($weakSelf): void {
                $adapter = $weakSelf->get();
                if ($adapter instanceof self) {
                    $adapter->handleDrain($connection);
                }
            },
            static function (Connection $connection) use ($weakSelf): void {
                $adapter = $weakSelf->get();
                if ($adapter instanceof self) {
                    $adapter->handleEof($connection);
                }
            },
        );
        $this->attached = true;
        $connection->onClose(static function (Connection $connection, CloseReason $reason) use ($weakSelf): void {
            $adapter = $weakSelf->get();
            if ($adapter instanceof self) {
                $adapter->handleClose($connection, $reason);
            }
        });
    }

    /**
     * Abort the underlying connection immediately.
     */
    public function abort(CloseReason $reason = CloseReason::LOCAL_ABORT): void
    {
        $this->assertUsable();
        $this->connection->abort($reason);
    }

    /**
     * Gracefully close the connection and wait for its close reason.
     */
    public function close(): CloseReason
    {
        $this->assertUsable();
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

    /**
     * Return the terminal close reason when available.
     */
    public function closeReason(): ?CloseReason
    {
        return $this->connection->closeReason();
    }

    /**
     * Detach coroutine callback ownership without closing the underlying connection.
     */
    public function dispose(): void
    {
        if ($this->disposed) {
            return;
        }

        $this->disposed = true;
        $error = new LogicException('Async connection adapter was disposed.');
        $receive = $this->receiveDeferred;
        $drain = $this->drainDeferred;
        $close = $this->closeDeferred;
        $this->receiveDeferred = null;
        $this->drainDeferred = null;
        $this->closeDeferred = null;
        $this->receiveLimit = PHP_INT_MAX;
        $this->receiving = false;
        $this->draining = false;
        $this->closing = false;
        $this->eofBuffer = '';
        $this->detachCallbacks();

        $receive?->reject($error);
        $drain?->reject($error);
        $close?->reject($error);
    }

    /**
     * Wait until write pressure drains or the connection closes.
     */
    public function drain(): ?CloseReason
    {
        $this->assertUsable();
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

    /**
     * Receive up to the requested number of bytes.
     */
    public function receive(int $maxBytes = PHP_INT_MAX): string
    {
        $this->assertUsable();
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
            $result = $deferred->future()->await();
            if (!is_string($result)) {
                throw new LogicException('Async connection receive completed with an invalid result.');
            }

            return $result;
        } finally {
            if ($this->receiveDeferred === $deferred) {
                $this->receiveDeferred = null;
            }
            $this->receiveLimit = PHP_INT_MAX;
            $this->receiving = false;
        }
    }

    /**
     * Return the current connection state.
     */
    public function state(): ConnectionState
    {
        return $this->connection->state();
    }

    /**
     * Write data through the underlying connection.
     */
    public function write(string $data): WriteResult
    {
        $this->assertUsable();

        return $this->connection->write($data);
    }

    private function assertUsable(): void
    {
        if ($this->disposed) {
            throw new LogicException('Async connection adapter is disposed.');
        }
    }

    private function detachCallbacks(): void
    {
        if (!$this->attached) {
            return;
        }

        $this->attached = false;
        $this->connection->releaseCallbacks($this);
    }

    private function handleClose(Connection $connection, CloseReason $reason): void
    {
        $this->resolveReceive('');
        $this->resolveDrain($reason);

        $deferred = $this->closeDeferred;
        $this->closeDeferred = null;
        $deferred?->resolve($reason);

        $this->detachCallbacks();
        unset($connection);
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
