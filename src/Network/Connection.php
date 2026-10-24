<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Network;

use Closure;
use Infocyph\Runwire\Loop\LoopInterface;
use Infocyph\Runwire\Network\Enum\CloseReason;
use Infocyph\Runwire\Network\Enum\ConnectionState;
use Infocyph\Runwire\Network\Enum\WriteState;
use Infocyph\Runwire\Network\Internal\ByteBudget;
use Infocyph\Runwire\Network\Internal\ByteQueue;
use Infocyph\Runwire\Network\Internal\ConnectionCallbackDispatcher;
use Infocyph\Runwire\Network\Internal\ConnectionCallbackOwnership;
use Infocyph\Runwire\Network\Internal\ConnectionCloseObserverTrait;
use Infocyph\Runwire\Network\Internal\ConnectionLifecycleOperationsTrait;
use Infocyph\Runwire\Network\Internal\ConnectionScopeOwnership;
use Infocyph\Runwire\Network\Internal\ConnectionTimeouts;
use Infocyph\Runwire\Network\Internal\StreamWriter;
use Infocyph\Runwire\Network\Internal\TcpSocketTuner;
use InvalidArgumentException;
use RuntimeException;

/**
 * Manages a non-blocking stream connection with bounded buffering, callbacks, backpressure, and timeouts.
 */
final class Connection
{
    use ConnectionCloseObserverTrait;
    use ConnectionLifecycleOperationsTrait;

    private readonly ConnectionCallbackOwnership $callbackOwnership;

    private readonly int $id;

    private readonly ByteQueue $receiveBuffer;

    private readonly ByteQueue $sendBuffer;

    private readonly int $startedAtNanoseconds;

    private readonly ConnectionTimeouts $timeouts;

    private int $backpressureEvents = 0;

    private int $bytesRead = 0;

    private int $bytesWritten = 0;

    private ?CloseReason $closeReason = null;

    private ?Closure $dataCallback = null;

    private ?Closure $drainCallback = null;

    private ?CloseReason $drainReason = null;

    private ?Closure $eofCallback = null;

    private bool $manualReadPause = false;

    private bool $peerReadClosed = false;

    private bool $pressureReadPause = false;

    private ?int $readWatcher = null;

    private int $rejectedWrites = 0;

    private ?ConnectionScopeOwnership $scopeOwnership = null;

    private ConnectionState $state = ConnectionState::OPEN;

    /** @var resource|null */
    private mixed $stream;

    private bool $writePressured = false;

    private ?int $writeWatcher = null;

    /** @param resource $stream */
    public function __construct(
        private readonly LoopInterface $loop,
        mixed $stream,
        private readonly ConnectionLimits $limits = new ConnectionLimits(),
        private readonly ?string $peerAddress = null,
        private readonly ?string $localAddress = null,
        private readonly ?string $negotiatedProtocol = null,
        private readonly bool $encrypted = false,
        private readonly ?ByteBudget $bufferBudget = null,
        private readonly bool $tcpTransport = false,
        private readonly ?bool $tcpNoDelayOverride = null,
        private readonly ?string $tlsPeerFingerprint = null,
        private readonly bool $strictTlsPeerEof = false,
    ) {
        if (!is_resource($stream) || get_resource_type($stream) !== 'stream') {
            throw new InvalidArgumentException('Connection requires a live stream resource.');
        }

        $this->stream = $stream;
        $this->id = get_resource_id($stream);
        $this->startedAtNanoseconds = self::nowNanoseconds();
        $this->receiveBuffer = new ByteQueue($bufferBudget);
        $this->sendBuffer = new ByteQueue($bufferBudget);
        $this->callbackOwnership = new ConnectionCallbackOwnership();
        $this->timeouts = new ConnectionTimeouts(
            $loop,
            $limits->idleTimeoutSeconds,
            $limits->lifetimeTimeoutSeconds,
            fn(CloseReason $reason) => $this->finalize($reason),
        );
        if (!stream_set_blocking($stream, false)) {
            throw new RuntimeException('Unable to configure connection stream as non-blocking.');
        }
        $this->syncReadWatcher();
    }

    /**
     * Abort the connection immediately with a close reason.
     */
    public function abort(CloseReason $reason = CloseReason::LOCAL_ABORT): void
    {
        if ($this->state === ConnectionState::CLOSED) {
            return;
        }

        $this->sendBuffer->clear();
        $this->finalize($reason);
    }

    /**
     * @internal Apply a protocol TCP_NODELAY default unless listener configuration explicitly owns it.
     */
    public function applyTcpNoDelayDefault(bool $enabled): void
    {
        TcpSocketTuner::applyNoDelayDefault(
            $this->stream,
            $this->tcpTransport,
            $this->tcpNoDelayOverride,
            $enabled,
        );
    }

    /**
     * @internal Return bytes currently available in the bounded send queue.
     */
    public function availableWriteBytes(): int
    {
        return max(0, $this->limits->maxSendBufferBytes - $this->sendBuffer->bytes());
    }

    /**
     * Return cumulative backpressure events.
     */
    public function backpressureEvents(): int
    {
        return $this->backpressureEvents;
    }

    /**
     * Return the shared worker byte budget when this connection is metered.
     */
    public function bufferBudget(): ?ByteBudget
    {
        return $this->bufferBudget;
    }

    /**
     * Return cumulative bytes read from the transport.
     */
    public function bytesRead(): int
    {
        return $this->bytesRead;
    }

    /**
     * Return cumulative bytes written to the transport.
     */
    public function bytesWritten(): int
    {
        return $this->bytesWritten;
    }

    /**
     * @internal Reserved for isolated adapters that require the single data/drain/EOF callback slots.
     * @param callable(self): void $onData
     * @param callable(self): void $onDrain
     * @param callable(self): void $onEof
     */
    public function claimCallbacks(
        object $owner,
        callable $onData,
        callable $onDrain,
        callable $onEof,
    ): void {
        $this->callbackOwnership->claim(
            $owner,
            $this->dataCallback !== null || $this->drainCallback !== null || $this->eofCallback !== null,
            $this->state === ConnectionState::CLOSED,
        );

        $this->dataCallback = Closure::fromCallable($onData);
        $this->drainCallback = Closure::fromCallable($onDrain);
        $this->eofCallback = Closure::fromCallable($onEof);

        if ($this->receiveBuffer->bytes() > 0) {
            $this->invoke($this->dataCallback);
        }
        if ($this->peerReadClosed) {
            $this->invoke($this->eofCallback);
        }
    }

    /**
     * Begin a graceful local close after queued writes drain.
     */
    public function closeGracefully(): void
    {
        if ($this->state !== ConnectionState::OPEN && $this->state !== ConnectionState::HALF_CLOSED) {
            return;
        }

        $this->beginDrain(CloseReason::LOCAL_GRACEFUL);
    }

    /**
     * Return the terminal close reason when closed.
     */
    public function closeReason(): ?CloseReason
    {
        return $this->closeReason;
    }

    /**
     * @internal Transfers configured callback slots to a protocol session.
     *
     * @param callable(self): void $onData
     * @param callable(self): void $onDrain
     * @param callable(self): void $onEof
     */
    public function handoffCallbacks(
        object $owner,
        callable $onData,
        callable $onDrain,
        callable $onEof,
    ): void {
        $this->callbackOwnership->handoff(
            $owner,
            $this->state === ConnectionState::CLOSED,
        );

        $this->dataCallback = Closure::fromCallable($onData);
        $this->drainCallback = Closure::fromCallable($onDrain);
        $this->eofCallback = Closure::fromCallable($onEof);

        if ($this->state !== ConnectionState::CLOSED && $this->receiveBuffer->bytes() > 0) {
            $this->invoke($this->dataCallback);
        }
        if ($this->state !== ConnectionState::CLOSED && $this->peerReadClosed) {
            $this->invoke($this->eofCallback);
        }
    }

    /**
     * Return the underlying stream resource identifier.
     */
    public function id(): int
    {
        return $this->id;
    }

    /**
     * Determine whether the transport is encrypted.
     */
    public function isEncrypted(): bool
    {
        return $this->encrypted;
    }

    /**
     * Determine whether reads are manually or pressure paused.
     */
    public function isReadPaused(): bool
    {
        return $this->manualReadPause || $this->pressureReadPause;
    }

    /**
     * Determine whether receive-buffer pressure paused reads.
     */
    public function isReadPressurePaused(): bool
    {
        return $this->pressureReadPause;
    }

    /**
     * Determine whether the send buffer is under write pressure.
     */
    public function isWritePressured(): bool
    {
        return $this->writePressured;
    }

    /**
     * Return connection lifetime in monotonic nanoseconds.
     */
    public function lifetimeNanoseconds(): int
    {
        return max(0, self::nowNanoseconds() - $this->startedAtNanoseconds);
    }

    /**
     * Return the local transport address when known.
     */
    public function localAddress(): ?string
    {
        return $this->localAddress;
    }

    /**
     * Return the negotiated application protocol when known.
     */
    public function negotiatedProtocol(): ?string
    {
        return $this->negotiatedProtocol;
    }

    /** @param callable(self): void $callback */
    public function onData(callable $callback): self
    {
        $this->assertCallbackSlotsUnclaimed();
        $this->dataCallback = Closure::fromCallable($callback);
        if ($this->receiveBuffer->bytes() > 0) {
            $this->invoke($this->dataCallback);
        }

        return $this;
    }

    /** @param callable(self): void $callback */
    public function onDrain(callable $callback): self
    {
        $this->assertCallbackSlotsUnclaimed();
        $this->drainCallback = Closure::fromCallable($callback);

        return $this;
    }

    /** @param callable(self): void $callback */
    public function onEof(callable $callback): self
    {
        $this->assertCallbackSlotsUnclaimed();
        $this->eofCallback = Closure::fromCallable($callback);
        if ($this->peerReadClosed) {
            $this->invoke($this->eofCallback);
        }

        return $this;
    }

    /**
     * Pause transport reads manually.
     */
    public function pauseReads(): void
    {
        if ($this->state === ConnectionState::CLOSED || $this->manualReadPause) {
            return;
        }

        $this->manualReadPause = true;
        $this->syncReadWatcher();
    }

    /**
     * Return the peer transport address when known.
     */
    public function peerAddress(): ?string
    {
        return $this->peerAddress;
    }

    /**
     * Determine whether the peer closed its read side.
     */
    public function peerReadClosed(): bool
    {
        return $this->peerReadClosed;
    }

    /**
     * Return queued outbound bytes.
     */
    public function pendingWriteBytes(): int
    {
        return $this->sendBuffer->bytes();
    }

    /**
     * Consume up to the requested number of buffered inbound bytes.
     */
    public function read(int $maxBytes = PHP_INT_MAX): string
    {
        if ($maxBytes < 0) {
            throw new InvalidArgumentException('Maximum read length cannot be negative.');
        }

        $data = $this->receiveBuffer->read($maxBytes);
        if ($this->pressureReadPause
            && $this->receiveBuffer->bytes() <= $this->limits->receiveLowWatermarkBytes) {
            $this->pressureReadPause = false;
            $this->syncReadWatcher();
        }

        return $data;
    }

    /**
     * Return currently buffered inbound bytes.
     */
    public function receivedBytes(): int
    {
        return $this->receiveBuffer->bytes();
    }

    /**
     * Return cumulative writes rejected by send-buffer limits.
     */
    public function rejectedWrites(): int
    {
        return $this->rejectedWrites;
    }

    /** @internal Releases callback slots previously claimed or handed off to an exclusive owner. */
    public function releaseCallbacks(object $owner): void
    {
        $this->callbackOwnership->release($owner);
        $this->dataCallback = null;
        $this->drainCallback = null;
        $this->eofCallback = null;
    }

    /**
     * Resume manually paused reads.
     */
    public function resumeReads(): void
    {
        if (($this->state !== ConnectionState::OPEN && $this->state !== ConnectionState::HALF_CLOSED)
            || !$this->manualReadPause) {
            return;
        }

        $this->manualReadPause = false;
        $this->syncReadWatcher();
    }

    /** Return the current connection lifecycle state. */
    public function state(): ConnectionState
    {
        return $this->state;
    }

    /** Return captured TLS peer certificate SHA-256, not a peer verification result. */
    public function tlsPeerFingerprint(): ?string
    {
        return $this->tlsPeerFingerprint;
    }

    private static function nowNanoseconds(): int
    {
        $now = hrtime(true);

        return is_int($now) ? $now : (int) $now;
    }

    private function assertCallbackSlotsUnclaimed(): void
    {
        $this->callbackOwnership->assertUnclaimed();
    }

    private function cancelWatcher(?int $id): void
    {
        if ($id !== null) {
            $this->loop->cancel($id);
        }
    }

    private function finalize(CloseReason $reason): void
    {
        if ($this->state === ConnectionState::CLOSED) {
            return;
        }

        $this->state = ConnectionState::CLOSED;
        $this->closeReason = $reason;
        $this->scopeOwnership?->dispose();
        $this->scopeOwnership = null;
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
        ConnectionCallbackDispatcher::dispatch(array_values($callbacks), $this, $reason);
    }

    private function handleReadable(): void
    {
        if (($this->state !== ConnectionState::OPEN && $this->state !== ConnectionState::HALF_CLOSED)
            || $this->isReadPaused() || $this->peerReadClosed) {
            return;
        }

        $stream = $this->stream;
        if (!is_resource($stream)) {
            $this->finalize(CloseReason::READ_ERROR);

            return;
        }

        $received = false;
        $sawEof = false;
        $this->readAvailable($stream, $received, $sawEof);

        if ($received && $this->state !== ConnectionState::CLOSED) {
            $this->invoke($this->dataCallback);
        }
        if ($sawEof && $this->state !== ConnectionState::CLOSED) {
            if ($this->encrypted && $this->strictTlsPeerEof) {
                // The PHP stream API cannot authenticate close_notify on bare EOF.
                $this->finalize(CloseReason::TLS_UNVERIFIED_EOF);
            } else {
                $this->markPeerEof();
            }
        }
    }

    private function handleWritable(): void
    {
        if ($this->state === ConnectionState::CLOSED) {
            return;
        }

        $stream = $this->stream;
        if (!is_resource($stream)) {
            $this->finalize(CloseReason::WRITE_ERROR);

            return;
        }

        $writtenThisTick = 0;
        while (!$this->sendBuffer->isEmpty() && $writtenThisTick < $this->limits->maxWriteBytesPerTick) {
            $chunk = $this->sendBuffer->front($this->limits->maxWriteBytesPerTick - $writtenThisTick);
            if ($chunk === '') {
                break;
            }

            $written = StreamWriter::write($stream, $chunk);
            if ($written === false) {
                $this->finalize(CloseReason::WRITE_ERROR);

                return;
            }
            if ($written === 0) {
                break;
            }

            $this->sendBuffer->discard($written);
            $this->bytesWritten += $written;
            $writtenThisTick += $written;
            $this->timeouts->touch();
        }

        $this->updateWritePressure();
        $this->syncWriteWatcher();
        if ($this->state === ConnectionState::DRAINING && $this->sendBuffer->isEmpty()) {
            $this->finalize($this->drainReason ?? CloseReason::LOCAL_GRACEFUL);
        }
    }

    private function invoke(?Closure $callback): void
    {
        ConnectionCallbackDispatcher::invoke(
            $callback,
            $this,
            fn() => $this->abort(),
        );
    }

    private function pauseForPressure(): void
    {
        if (!$this->pressureReadPause) {
            ++$this->backpressureEvents;
        }
        $this->pressureReadPause = true;
        $this->syncReadWatcher();
    }

    /** @param resource $stream */
    private function readAvailable(mixed $stream, bool &$received, bool &$sawEof): void
    {
        $readThisTick = 0;

        while ($readThisTick < $this->limits->maxReadBytesPerTick) {
            $capacity = min(
                $this->limits->maxReceiveBufferBytes - $this->receiveBuffer->bytes(),
                $this->receiveBuffer->budgetAvailable(),
            );
            if ($capacity <= 0) {
                $this->pauseForPressure();

                break;
            }

            $length = max(1, min(
                $this->limits->readChunkBytes,
                $this->limits->maxReadBytesPerTick - $readThisTick,
                $capacity,
            ));
            $chunk = fread($stream, $length);
            if ($chunk === false) {
                $this->finalize(CloseReason::READ_ERROR);

                return;
            }
            if ($chunk === '') {
                $sawEof = feof($stream);

                break;
            }

            $bytes = strlen($chunk);
            $this->receiveBuffer->append($chunk);
            $this->bytesRead += $bytes;
            $readThisTick += $bytes;
            $received = true;
            $this->timeouts->touch();

            if ($this->receiveBuffer->bytes() >= $this->limits->receiveHighWatermarkBytes) {
                $this->pauseForPressure();

                break;
            }
        }

    }

    private function syncReadWatcher(): void
    {
        $stream = $this->stream;
        $shouldWatch = ($this->state === ConnectionState::OPEN || $this->state === ConnectionState::HALF_CLOSED)
            && !$this->manualReadPause
            && !$this->pressureReadPause
            && !$this->peerReadClosed
            && is_resource($stream);

        if ($shouldWatch) {
            $this->readWatcher ??= $this->loop->onReadable(
                $stream,
                function (): void {
                    $this->handleReadable();
                },
            );

            return;
        }
        $this->cancelWatcher($this->readWatcher);
        $this->readWatcher = null;
    }

    private function syncWriteWatcher(): void
    {
        $stream = $this->stream;
        $shouldWatch = $this->state !== ConnectionState::CLOSED
            && !$this->sendBuffer->isEmpty()
            && is_resource($stream);

        if ($shouldWatch) {
            $this->writeWatcher ??= $this->loop->onWritable(
                $stream,
                function (): void {
                    $this->handleWritable();
                },
            );

            return;
        }
        $this->cancelWatcher($this->writeWatcher);
        $this->writeWatcher = null;
    }

    private function updateWritePressure(): void
    {
        $bytes = $this->sendBuffer->bytes();
        if (!$this->writePressured && $bytes >= $this->limits->sendHighWatermarkBytes) {
            $this->writePressured = true;
            ++$this->backpressureEvents;

            return;
        }
        if ($this->writePressured && $bytes <= $this->limits->sendLowWatermarkBytes) {
            $this->writePressured = false;
            $this->invoke($this->drainCallback);
        }
    }

    private function writeImmediately(string &$data, int $length): ?WriteResult
    {
        $stream = $this->stream;
        if (!is_resource($stream)) {
            $this->finalize(CloseReason::WRITE_ERROR);

            return new WriteResult(WriteState::CLOSED, 0);
        }
        $attempt = max(0, min($length, $this->limits->maxWriteBytesPerTick));
        $written = StreamWriter::write($stream, $data, $attempt);
        if ($written === false) {
            $this->finalize(CloseReason::WRITE_ERROR);

            return new WriteResult(WriteState::CLOSED, 0);
        }

        if ($written > 0) {
            $this->bytesWritten += $written;
            $this->timeouts->touch();
            if ($written === $length) {
                return $this->writeResult();
            }
            $data = substr($data, $written);
        }

        return null;
    }

    private function writeResult(): WriteResult
    {
        return new WriteResult(
            $this->writePressured ? WriteState::PRESSURED : WriteState::ACCEPTED,
            $this->sendBuffer->bytes(),
        );
    }
}
