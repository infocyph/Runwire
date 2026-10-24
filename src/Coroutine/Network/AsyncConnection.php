<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Coroutine\Network;

use Infocyph\Runwire\CancellationToken;
use Infocyph\Runwire\Coroutine\CoroutineScope;
use Infocyph\Runwire\Coroutine\Deferred;
use Infocyph\Runwire\Network\Connection;
use Infocyph\Runwire\Network\Enum\CloseReason;
use Infocyph\Runwire\Network\Enum\ConnectionState;
use Infocyph\Runwire\Network\WriteResult;
use Infocyph\Runwire\RequestDeadline;
use InvalidArgumentException;
use LogicException;
use RuntimeException;
use Throwable;
use WeakReference;

/**
 * Provides coroutine-friendly waiting around a network connection.
 */
final class AsyncConnection
{
    private const string UNVERIFIED_TLS_EOF_MESSAGE = 'TLS peer EOF was not authenticated by close_notify.';

    private bool $attached;

    private ?Deferred $closeDeferred = null;

    private ?int $closeObserverId;

    private bool $closing = false;

    private bool $disposed = false;

    private ?Deferred $drainDeferred = null;

    private bool $draining = false;

    private string $eofBuffer = '';

    private ?Deferred $receiveDeferred = null;

    private int $receiveLimit = PHP_INT_MAX;

    private bool $receiving = false;

    private ?int $scopeShutdownId = null;

    /**
     * Bind coroutine waits to the supplied connection.
     */
    public function __construct(private readonly Connection $connection, private readonly CoroutineScope $scope)
    {
        $weakSelf = WeakReference::create($this);
        $this->connection->claimCallbacks(
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
        $this->closeObserverId = $this->connection->registerCloseObserver(
            static function (Connection $connection, CloseReason $reason) use ($weakSelf): void {
                $adapter = $weakSelf->get();
                if ($adapter instanceof self) {
                    $adapter->handleClose($connection, $reason);
                }
            },
        );
        if (!$this->connection->boundTo($scope->cancellation())) {
            $this->connection->bindOwner($scope->cancellation());
        }
        if ($this->connection->state() !== ConnectionState::CLOSED) {
            $this->scopeShutdownId = $scope->registerShutdown(
                static function () use ($connection): void {
                    $connection->abort();
                },
            );
        }
    }

    /**
     * Adopt an explicitly pool-owned connection into a new coroutine scope.
     * Failure aborts the stream rather than leaving an unobserved owner.
     */
    public static function adopt(Connection $connection, CoroutineScope $scope): self
    {
        $connection->transferOwner($scope->cancellation());

        try {
            return new self($connection, $scope);
        } catch (Throwable $error) {
            $connection->abort();

            throw $error;
        }
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
        $this->releaseCloseObserver();
        // Disposing a Fiber adapter is not a resource ownership transfer.
        // Keep the established connection registered with its scope so
        // normal scope exit still closes the socket and releases its watchers.
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
            if ($this->connection->closeReason() === CloseReason::TLS_UNVERIFIED_EOF) {
                throw new RuntimeException(self::UNVERIFIED_TLS_EOF_MESSAGE);
            }

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
     * Transfer an idle connection to a new owner and detach this coroutine adapter.
     * Only the returned core connection may be used after handoff.
     */
    public function transferTo(CancellationToken $owner, ?RequestDeadline $deadline = null): Connection
    {
        $this->assertUsable();
        if ($this->connection->state() === ConnectionState::CLOSED) {
            throw new LogicException('A closed connection cannot be transferred.');
        }
        if ($this->receiving || $this->draining || $this->closing || $this->eofBuffer !== '') {
            throw new LogicException('Cannot transfer a connection with active coroutine waits or buffered EOF.');
        }
        if ($owner === $this->scope->cancellation()) {
            throw new LogicException('Connection transfer requires a different owner token.');
        }

        $this->connection->transferOwner($owner, $deadline);
        $this->disposed = true;
        $this->releaseCloseObserver();
        $this->releaseScopeShutdown();
        $this->detachCallbacks();

        return $this->connection;
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
        $this->releaseCloseObserver();
        $this->releaseScopeShutdown();
        if ($reason === CloseReason::TLS_UNVERIFIED_EOF) {
            $pending = $this->receiveDeferred;
            $this->receiveDeferred = null;
            $pending?->reject(new RuntimeException(self::UNVERIFIED_TLS_EOF_MESSAGE));
        } else {
            $this->resolveReceive('');
        }
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

    private function releaseCloseObserver(): void
    {
        if ($this->closeObserverId === null) {
            return;
        }

        $this->connection->unregisterCloseObserver($this->closeObserverId);
        $this->closeObserverId = null;
    }

    private function releaseScopeShutdown(): void
    {
        if ($this->scopeShutdownId === null) {
            return;
        }

        $this->scope->unregisterShutdown($this->scopeShutdownId);
        $this->scopeShutdownId = null;
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
