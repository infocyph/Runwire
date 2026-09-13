<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Network;

use Closure;
use Infocyph\Runwire\Loop\LoopInterface;
use Infocyph\Runwire\Network\Enum\CloseReason;
use Infocyph\Runwire\Network\Enum\ConnectionState;
use Infocyph\Runwire\Network\Enum\WriteState;
use Infocyph\Runwire\Network\Internal\ByteQueue;
use Infocyph\Runwire\Network\Internal\ConnectionCallbackDispatcher;
use Infocyph\Runwire\Network\Internal\ConnectionTimeouts;
use InvalidArgumentException;
use LogicException;
use RuntimeException;
use Throwable;

final class Connection
{
    private readonly int $id;

    private readonly ByteQueue $receiveBuffer;

    private readonly ByteQueue $sendBuffer;

    private readonly int $startedAtNanoseconds;

    private readonly ConnectionTimeouts $timeouts;

    private int $backpressureEvents = 0;

    private int $bytesRead = 0;

    private int $bytesWritten = 0;

    private ?object $callbackOwner = null;

    /** @var list<Closure> */
    private array $closeCallbacks = [];

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
    ) {
        if (!is_resource($stream) || get_resource_type($stream) !== 'stream') {
            throw new InvalidArgumentException('Connection requires a live stream resource.');
        }

        $this->stream = $stream;
        $this->id = get_resource_id($stream);
        $this->startedAtNanoseconds = self::nowNanoseconds();
        $this->receiveBuffer = new ByteQueue();
        $this->sendBuffer = new ByteQueue();
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

    public function abort(CloseReason $reason = CloseReason::LOCAL_ABORT): void
    {
        if ($this->state === ConnectionState::CLOSED) {
            return;
        }

        $this->sendBuffer->clear();
        $this->finalize($reason);
    }

    public function backpressureEvents(): int
    {
        return $this->backpressureEvents;
    }

    public function bytesRead(): int
    {
        return $this->bytesRead;
    }

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
        if ($this->callbackOwner !== null) {
            throw new LogicException('Connection callback slots are already exclusively owned.');
        }
        if ($this->dataCallback !== null || $this->drainCallback !== null || $this->eofCallback !== null) {
            throw new LogicException('Connection callbacks are already configured and cannot be claimed exclusively.');
        }
        if ($this->state === ConnectionState::CLOSED) {
            throw new LogicException('Closed connections cannot grant exclusive callback ownership.');
        }

        $this->callbackOwner = $owner;
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

    public function closeGracefully(): void
    {
        if ($this->state !== ConnectionState::OPEN) {
            return;
        }

        $this->beginDrain(CloseReason::LOCAL_GRACEFUL);
    }

    public function closeReason(): ?CloseReason
    {
        return $this->closeReason;
    }

    public function id(): int
    {
        return $this->id;
    }

    public function isEncrypted(): bool
    {
        return $this->encrypted;
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

    public function lifetimeNanoseconds(): int
    {
        return max(0, self::nowNanoseconds() - $this->startedAtNanoseconds);
    }

    public function localAddress(): ?string
    {
        return $this->localAddress;
    }

    public function negotiatedProtocol(): ?string
    {
        return $this->negotiatedProtocol;
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

    public function pauseReads(): void
    {
        if ($this->state === ConnectionState::CLOSED || $this->manualReadPause) {
            return;
        }

        $this->manualReadPause = true;
        $this->syncReadWatcher();
    }

    public function peerAddress(): ?string
    {
        return $this->peerAddress;
    }

    public function peerReadClosed(): bool
    {
        return $this->peerReadClosed;
    }

    public function pendingWriteBytes(): int
    {
        return $this->sendBuffer->bytes();
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

    public function receivedBytes(): int
    {
        return $this->receiveBuffer->bytes();
    }

    public function rejectedWrites(): int
    {
        return $this->rejectedWrites;
    }

    /** @internal Releases callback slots previously claimed through claimCallbacks(). */
    public function releaseCallbacks(object $owner): void
    {
        if ($this->callbackOwner !== $owner) {
            throw new LogicException('Only the exclusive callback owner may release connection callback slots.');
        }

        $this->callbackOwner = null;
        $this->dataCallback = null;
        $this->drainCallback = null;
        $this->eofCallback = null;
    }

    public function resumeReads(): void
    {
        if ($this->state !== ConnectionState::OPEN || !$this->manualReadPause) {
            return;
        }

        $this->manualReadPause = false;
        $this->syncReadWatcher();
    }

    public function state(): ConnectionState
    {
        return $this->state;
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
            ++$this->rejectedWrites;

            return new WriteResult(WriteState::REJECTED_LIMIT, $this->sendBuffer->bytes());
        }

        if ($this->sendBuffer->isEmpty()) {
            $stream = $this->stream;
            if (!is_resource($stream)) {
                $this->finalize(CloseReason::WRITE_ERROR);

                return new WriteResult(WriteState::CLOSED, 0);
            }
            $attempt = max(0, min($length, $this->limits->maxWriteBytesPerTick));
            $written = fwrite($stream, $data, $attempt);
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
        }

        $this->sendBuffer->append($data);
        $this->syncWriteWatcher();
        $this->updateWritePressure();

        return $this->writeResult();
    }

    private static function nowNanoseconds(): int
    {
        $now = hrtime(true);

        return is_int($now) ? $now : (int) $now;
    }

    private function assertCallbackSlotsUnclaimed(): void
    {
        if ($this->callbackOwner !== null) {
            throw new LogicException('Connection callback slots are exclusively owned by an adapter.');
        }
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

    private function finalize(CloseReason $reason): void
    {
        if ($this->state === ConnectionState::CLOSED) {
            return;
        }

        $this->state = ConnectionState::CLOSED;
        $this->closeReason = $reason;
        foreach ([$this->readWatcher, $this->writeWatcher] as $handle) {
            if ($handle !== null) {
                $this->loop->cancel($handle);
            }
        }
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

    private function handleReadable(): void
    {
        if ($this->state !== ConnectionState::OPEN || $this->isReadPaused() || $this->peerReadClosed) {
            return;
        }

        $stream = $this->stream;
        if (!is_resource($stream)) {
            $this->finalize(CloseReason::READ_ERROR);

            return;
        }

        $readThisTick = 0;
        $received = false;
        $sawEof = false;

        while ($readThisTick < $this->limits->maxReadBytesPerTick) {
            $capacity = $this->limits->maxReceiveBufferBytes - $this->receiveBuffer->bytes();
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

            $written = fwrite($stream, $chunk);
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
        try {
            $callback?->__invoke($this);
        } catch (Throwable $throwable) {
            try {
                $this->abort();
            } catch (Throwable) {
                // Preserve the originating callback failure after deterministic cleanup.
            }

            throw $throwable;
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

    private function pauseForPressure(): void
    {
        if (!$this->pressureReadPause) {
            ++$this->backpressureEvents;
        }
        $this->pressureReadPause = true;
        $this->syncReadWatcher();
    }

    private function syncReadWatcher(): void
    {
        $stream = $this->stream;
        $shouldWatch = $this->state === ConnectionState::OPEN
            && !$this->manualReadPause
            && !$this->pressureReadPause
            && !$this->peerReadClosed
            && is_resource($stream);

        if ($shouldWatch && $this->readWatcher === null) {
            $this->readWatcher = $this->loop->onReadable(
                $stream,
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
        $stream = $this->stream;
        $shouldWatch = $this->state !== ConnectionState::CLOSED
            && !$this->sendBuffer->isEmpty()
            && is_resource($stream);

        if ($shouldWatch && $this->writeWatcher === null) {
            $this->writeWatcher = $this->loop->onWritable(
                $stream,
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
            ++$this->backpressureEvents;
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
}
