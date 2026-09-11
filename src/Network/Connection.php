<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Network;

use Closure;
use Infocyph\Runwire\Loop\LoopInterface;
use Infocyph\Runwire\Network\Internal\ByteQueue;
use InvalidArgumentException;
use Throwable;

final class Connection
{
    private readonly int $id;
    private readonly ByteQueue $receiveBuffer;
    private readonly ByteQueue $sendBuffer;
    private ConnectionState $state = ConnectionState::OPEN;
    private ?CloseReason $closeReason = null;
    private ?CloseReason $drainReason = null;
    private ?int $readWatcher = null;
    private ?int $writeWatcher = null;
    private ?int $idleTimer = null;
    private ?int $lifetimeTimer = null;
    private bool $manualReadPause = false;
    private bool $pressureReadPause = false;
    private bool $writePressured = false;
    private bool $peerReadClosed = false;
    private int $bytesRead = 0;
    private int $bytesWritten = 0;
    private float $lastActivityAt;
    private ?Closure $dataCallback = null;
    private ?Closure $drainCallback = null;
    private ?Closure $eofCallback = null;

    /** @var list<Closure> */
    private array $closeCallbacks = [];

    /**
     * @param resource $stream
     */
    public function __construct(
        private readonly LoopInterface $loop,
        private mixed $stream,
        private readonly ConnectionLimits $limits = new ConnectionLimits(),
        private readonly ?string $peerAddress = null,
        private readonly ?string $localAddress = null,
        private readonly ?string $negotiatedProtocol = null,
        private readonly bool $encrypted = false,
    ) {
        if (!is_resource($stream) || get_resource_type($stream) !== 'stream') {
            throw new InvalidArgumentException('Connection requires a live stream resource.');
        }

        $this->id = get_resource_id($stream);
        $this->receiveBuffer = new ByteQueue();
        $this->sendBuffer = new ByteQueue();
        $this->lastActivityAt = $loop->now();
        @stream_set_blocking($this->stream, false);
        $this->syncReadWatcher();
        $this->armIdleTimer($limits->idleTimeoutSeconds);
        $this->armLifetimeTimer($limits->lifetimeTimeoutSeconds);
    }

    public function id(): int
    {
        return $this->id;
    }

    public function state(): ConnectionState
    {
        return $this->state;
    }

    public function closeReason(): ?CloseReason
    {
        return $this->closeReason;
    }

    public function peerAddress(): ?string
    {
        return $this->peerAddress;
    }

    public function localAddress(): ?string
    {
        return $this->localAddress;
    }

    public function negotiatedProtocol(): ?string
    {
        return $this->negotiatedProtocol;
    }

    public function isEncrypted(): bool
    {
        return $this->encrypted;
    }

    public function bytesRead(): int
    {
        return $this->bytesRead;
    }

    public function bytesWritten(): int
    {
        return $this->bytesWritten;
    }

    public function receivedBytes(): int
    {
        return $this->receiveBuffer->bytes();
    }

    public function pendingWriteBytes(): int
    {
        return $this->sendBuffer->bytes();
    }

    public function isReadPaused(): bool
    {
        return $this->manualReadPause || $this->pressureReadPause;
    }

    public function isReadPressurePaused(): bool
    {
        return $this->pressureReadPause;
    }

    public function isWritePressured(): bool
    {
        return $this->writePressured;
    }

    public function peerReadClosed(): bool
    {
        return $this->peerReadClosed;
    }

    /** @param callable(self): void $callback */
    public function onData(callable $callback): self
    {
        $this->dataCallback = Closure::fromCallable($callback);
        if ($this->receiveBuffer->bytes() > 0) {
            $this->invoke($this->dataCallback);
        }

        return $this;
    }

    /** @param callable(self): void $callback */
    public function onDrain(callable $callback): self
    {
        $this->drainCallback = Closure::fromCallable($callback);

        return $this;
    }

    /** @param callable(self): void $callback */
    public function onEof(callable $callback): self
    {
        $this->eofCallback = Closure::fromCallable($callback);
        if ($this->peerReadClosed) {
            $this->invoke($this->eofCallback);
        }

        return $this;
    }

    /** @param callable(self, CloseReason): void $callback */
    public function onClose(callable $callback): self
    {
        $closure = Closure::fromCallable($callback);
        if ($this->state === ConnectionState::CLOSED && $this->closeReason !== null) {
            $closure($this, $this->closeReason);
            return $this;
        }

        $this->closeCallbacks[] = $closure;

        return $this;
    }

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

    public function pauseReads(): void
    {
        if ($this->state === ConnectionState::CLOSED || $this->manualReadPause) {
            return;
        }

        $this->manualReadPause = true;
        $this->syncReadWatcher();
    }

    public function resumeReads(): void
    {
        if ($this->state !== ConnectionState::OPEN || !$this->manualReadPause) {
            return;
        }

        $this->manualReadPause = false;
        $this->syncReadWatcher();
    }

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
            return new WriteResult(WriteState::REJECTED_LIMIT, $this->sendBuffer->bytes());
        }

        if ($this->sendBuffer->isEmpty()) {
            $attempt = min($length, $this->limits->maxWriteBytesPerTick);
            $written = @fwrite($this->stream, $data, $attempt);
            if ($written === false) {
                $this->finalize(CloseReason::WRITE_ERROR);
                return new WriteResult(WriteState::CLOSED, 0);
            }

            if ($written > 0) {
                $this->bytesWritten += $written;
                $this->touch();
                if ($written === $length) {
                    return $this->writeResult();
                }
                $data = (string) substr($data, $written);
            }
        }

        $this->sendBuffer->append($data);
        $this->syncWriteWatcher();
        $this->updateWritePressure();

        return $this->writeResult();
    }

    public function closeGracefully(): void
    {
        if ($this->state !== ConnectionState::OPEN) {
            return;
        }

        $this->beginDrain(CloseReason::LOCAL_GRACEFUL);
    }

    public function abort(CloseReason $reason = CloseReason::LOCAL_ABORT): void
    {
        if ($this->state === ConnectionState::CLOSED) {
            return;
        }

        $this->sendBuffer->clear();
        $this->finalize($reason);
    }

    private function handleReadable(): void
    {
        if ($this->state !== ConnectionState::OPEN || $this->isReadPaused() || $this->peerReadClosed) {
            return;
        }

        $readThisTick = 0;
        $received = false;
        $sawEof = false;

        while ($readThisTick < $this->limits->maxReadBytesPerTick) {
            $capacity = $this->limits->maxReceiveBufferBytes - $this->receiveBuffer->bytes();
            if ($capacity <= 0) {
                $this->pressureReadPause = true;
                $this->syncReadWatcher();
                break;
            }

            $length = min(
                $this->limits->readChunkBytes,
                $this->limits->maxReadBytesPerTick - $readThisTick,
                $capacity,
            );
            $chunk = @fread($this->stream, $length);
            if ($chunk === false) {
                $this->finalize(CloseReason::READ_ERROR);
                return;
            }

            if ($chunk === '') {
                $sawEof = feof($this->stream);
                break;
            }

            $bytes = strlen($chunk);
            $this->receiveBuffer->append($chunk);
            $this->bytesRead += $bytes;
            $readThisTick += $bytes;
            $received = true;
            $this->touch();

            if ($this->receiveBuffer->bytes() >= $this->limits->receiveHighWatermarkBytes) {
                $this->pressureReadPause = true;
                $this->syncReadWatcher();
                break;
            }
        }

        if ($received && $this->state === ConnectionState::OPEN) {
            $this->invoke($this->dataCallback);
        }

        if ($sawEof && $this->state !== ConnectionState::CLOSED) {
            $this->markPeerEof();
        }
    }

    private function handleWritable(): void
    {
        if ($this->state === ConnectionState::CLOSED) {
            return;
        }

        $writtenThisTick = 0;
        while (!$this->sendBuffer->isEmpty() && $writtenThisTick < $this->limits->maxWriteBytesPerTick) {
            $chunk = $this->sendBuffer->front($this->limits->maxWriteBytesPerTick - $writtenThisTick);
            if ($chunk === '') {
                break;
            }

            $written = @fwrite($this->stream, $chunk);
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
            $this->touch();
        }

        $this->updateWritePressure();
        $this->syncWriteWatcher();

        if ($this->state === ConnectionState::DRAINING && $this->sendBuffer->isEmpty()) {
            $this->finalize($this->drainReason ?? CloseReason::LOCAL_GRACEFUL);
        }
    }

    private function updateWritePressure(): void
    {
        $bytes = $this->sendBuffer->bytes();
        if (!$this->writePressured && $bytes >= $this->limits->sendHighWatermarkBytes) {
            $this->writePressured = true;
            return;
        }

        if ($this->writePressured && $bytes <= $this->limits->sendLowWatermarkBytes) {
            $this->writePressured = false;
            $this->invoke($this->drainCallback);
        }
    }

    private function writeResult(): WriteResult
    {
        return new WriteResult(
            $this->writePressured ? WriteState::PRESSURED : WriteState::ACCEPTED,
            $this->sendBuffer->bytes(),
        );
    }

    private function syncReadWatcher(): void
    {
        $shouldWatch = $this->state === ConnectionState::OPEN
            && !$this->manualReadPause
            && !$this->pressureReadPause
            && !$this->peerReadClosed
            && is_resource($this->stream);

        if ($shouldWatch && $this->readWatcher === null) {
            $this->readWatcher = $this->loop->onReadable(
                $this->stream,
                function (): void {
                    $this->handleReadable();
                },
            );
            return;
        }

        if (!$shouldWatch && $this->readWatcher !== null) {
            $this->loop->cancel($this->readWatcher);
            $this->readWatcher = null;
        }
    }

    private function syncWriteWatcher(): void
    {
        $shouldWatch = $this->state !== ConnectionState::CLOSED
            && !$this->sendBuffer->isEmpty()
            && is_resource($this->stream);

        if ($shouldWatch && $this->writeWatcher === null) {
            $this->writeWatcher = $this->loop->onWritable(
                $this->stream,
                function (): void {
                    $this->handleWritable();
                },
            );
            return;
        }

        if (!$shouldWatch && $this->writeWatcher !== null) {
            $this->loop->cancel($this->writeWatcher);
            $this->writeWatcher = null;
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
        }
    }

    private function invoke(?Closure $callback): void
    {
        if ($callback === null) {
            return;
        }

        try {
            $callback($this);
        } catch (Throwable $throwable) {
            try {
                $this->abort();
            } catch (Throwable) {
                // Preserve the originating callback failure after deterministic cleanup.
            }
            throw $throwable;
        }
    }

    private function touch(): void
    {
        $this->lastActivityAt = $this->loop->now();
    }

    private function armIdleTimer(?float $delay): void
    {
        if ($delay === null) {
            return;
        }

        $this->idleTimer = $this->loop->delay($delay, function (): void {
            $this->idleTimer = null;
            if ($this->state === ConnectionState::CLOSED) {
                return;
            }

            $limit = $this->limits->idleTimeoutSeconds;
            if ($limit === null) {
                return;
            }

            $remaining = $limit - ($this->loop->now() - $this->lastActivityAt);
            if ($remaining <= 0) {
                $this->finalize(CloseReason::IDLE_TIMEOUT);
                return;
            }

            $this->armIdleTimer($remaining);
        });
    }

    private function beginDrain(CloseReason $reason): void
    {
        if ($this->state !== ConnectionState::OPEN) {
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

    private function armLifetimeTimer(?float $seconds): void
    {
        if ($seconds === null) {
            return;
        }

        $this->lifetimeTimer = $this->loop->delay($seconds, function (): void {
            $this->lifetimeTimer = null;
            if ($this->state !== ConnectionState::CLOSED) {
                $this->finalize(CloseReason::LIFETIME_TIMEOUT);
            }
        });
    }

    private function finalize(CloseReason $reason): void
    {
        if ($this->state === ConnectionState::CLOSED) {
            return;
        }

        $this->state = ConnectionState::CLOSED;
        $this->closeReason = $reason;
        foreach ([$this->readWatcher, $this->writeWatcher, $this->idleTimer, $this->lifetimeTimer] as $handle) {
            if ($handle !== null) {
                $this->loop->cancel($handle);
            }
        }
        $this->readWatcher = $this->writeWatcher = $this->idleTimer = $this->lifetimeTimer = null;

        if (is_resource($this->stream)) {
            @fclose($this->stream);
        }
        $this->stream = null;
        $this->receiveBuffer->clear();
        $this->sendBuffer->clear();

        $callbacks = $this->closeCallbacks;
        $this->closeCallbacks = [];
        $firstFailure = null;
        foreach ($callbacks as $callback) {
            try {
                $callback($this, $reason);
            } catch (Throwable $throwable) {
                $firstFailure ??= $throwable;
            }
        }

        if ($firstFailure !== null) {
            throw $firstFailure;
        }
    }
}
