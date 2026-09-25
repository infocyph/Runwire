<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Internal;

use Closure;
use Infocyph\Runwire\Http\Headers;
use Infocyph\Runwire\Http\RequestBodyInterface;
use Infocyph\Runwire\Network\Internal\ByteQueue;
use InvalidArgumentException;
use OverflowException;
use Throwable;

/**
 * Buffers an incrementally received request body with bounded backpressure and cancellation hooks.
 */
final class StreamingRequestBody implements RequestBodyInterface
{
    private const int MAX_CANCEL_OBSERVERS = 8;

    private readonly ByteQueue $buffer;

    /** @var Closure(int): void|null */
    private readonly ?Closure $onConsumed;

    /** @var Closure(): void */
    private readonly Closure $onRelief;

    private ?Closure $cancelCallback = null;

    private bool $cancelled = false;

    /** @var list<Closure(): void> */
    private array $cancelObservers = [];

    private ?Closure $dataCallback = null;

    private bool $dataNotificationPending = false;

    private bool $dataNotifying = false;

    private ?Closure $endCallback = null;

    private bool $ended = false;

    private bool $pressured = false;

    private int $received = 0;

    private ?Headers $trailers = null;

    /**
     * @param callable(): void $onRelief
     * @param callable(int): void|null $onConsumed
     */
    public function __construct(
        private readonly int $lowWatermark,
        private readonly int $highWatermark,
        private readonly int $maxBufferBytes,
        callable $onRelief,
        ?callable $onConsumed = null,
    ) {
        if ($lowWatermark < 0 || $lowWatermark >= $highWatermark || $highWatermark > $maxBufferBytes) {
            throw new InvalidArgumentException('Body buffer watermarks must satisfy 0 <= low < high <= max.');
        }
        $this->buffer = new ByteQueue();
        $this->onRelief = Closure::fromCallable($onRelief);
        $this->onConsumed = $onConsumed === null ? null : Closure::fromCallable($onConsumed);
    }

    /**
     * Return the number of unread buffered bytes.
     */
    public function bufferedBytes(): int
    {
        return $this->buffer->bytes();
    }

    /** @internal */
    public function cancel(bool $notifyRuntimeObservers = true): void
    {
        if ($this->cancelled) {
            return;
        }
        $this->cancelled = true;
        if ($this->ended) {
            if ($notifyRuntimeObservers) {
                $this->invokeCancelObservers();
            } else {
                $this->cancelObservers = [];
            }

            return;
        }
        $discarded = $this->buffer->bytes();
        $this->buffer->clear();
        $this->pressured = false;
        if ($discarded > 0 && $this->onConsumed !== null) {
            ($this->onConsumed)($discarded);
        }
        if ($notifyRuntimeObservers) {
            $this->invokeCancelObservers();
        } else {
            $this->cancelObservers = [];
        }
        $this->invoke($this->cancelCallback);
    }

    /**
     * Determine whether body delivery was cancelled.
     */
    public function cancelled(): bool
    {
        return $this->cancelled;
    }

    /**
     * Return remaining body-buffer capacity in bytes.
     */
    public function capacity(): int
    {
        return $this->maxBufferBytes - $this->buffer->bytes();
    }

    /** @internal */
    public function discardBuffered(): void
    {
        $hadPressure = $this->pressured;
        $discarded = $this->buffer->bytes();
        $this->buffer->clear();
        if ($discarded > 0 && $this->onConsumed !== null) {
            ($this->onConsumed)($discarded);
        }
        $this->pressured = false;
        if ($hadPressure) {
            ($this->onRelief)();
        }
    }

    /**
     * Determine whether the producer has ended the body stream.
     */
    public function ended(): bool
    {
        return $this->ended;
    }

    /**
     * Determine whether the body has ended and all buffered bytes are consumed.
     */
    public function eof(): bool
    {
        return $this->ended && $this->buffer->isEmpty();
    }

    /** @internal */
    public function finish(?Headers $trailers = null): void
    {
        if ($this->ended || $this->cancelled) {
            return;
        }
        $this->ended = true;
        $this->trailers = $trailers ?? new Headers();
        $this->invoke($this->endCallback);
    }

    /**
     * @internal
     * @param callable(): void $callback
     */
    public function observeCancel(callable $callback): void
    {
        $closure = Closure::fromCallable($callback);
        if ($this->cancelled) {
            self::invokeObserver($closure);

            return;
        }
        if (count($this->cancelObservers) >= self::MAX_CANCEL_OBSERVERS) {
            throw new OverflowException('Streaming request body cancellation observer limit exceeded.');
        }

        $this->cancelObservers[] = $closure;
    }

    /** @param callable(RequestBodyInterface): void $callback */
    public function onCancel(callable $callback): RequestBodyInterface
    {
        $this->cancelCallback = Closure::fromCallable($callback);
        if ($this->cancelled) {
            $this->invoke($this->cancelCallback);
        }

        return $this;
    }

    /** @param callable(RequestBodyInterface): void $callback */
    public function onData(callable $callback): RequestBodyInterface
    {
        $this->dataCallback = Closure::fromCallable($callback);
        if (!$this->buffer->isEmpty()) {
            $this->notifyData();
        }

        return $this;
    }

    /** @param callable(RequestBodyInterface): void $callback */
    public function onEnd(callable $callback): RequestBodyInterface
    {
        $this->endCallback = Closure::fromCallable($callback);
        if ($this->ended) {
            $this->invoke($this->endCallback);
        }

        return $this;
    }

    /**
     * Determine whether body buffering is currently applying pressure.
     */
    public function pressured(): bool
    {
        return $this->pressured;
    }

    /** @internal */
    public function push(string $bytes): bool
    {
        if ($bytes === '' || $this->ended || $this->cancelled) {
            return !$this->pressured;
        }
        if (strlen($bytes) > $this->capacity()) {
            return false;
        }
        $this->buffer->append($bytes);
        $this->received += strlen($bytes);
        $this->notifyData();
        if ($this->buffer->bytes() >= $this->highWatermark) {
            $this->pressured = true;
        }

        return !$this->pressured;
    }

    /**
     * Read up to the requested number of buffered body bytes.
     */
    public function read(int $maxBytes = PHP_INT_MAX): string
    {
        if ($maxBytes < 0) {
            throw new InvalidArgumentException('Maximum body read length cannot be negative.');
        }
        $data = $this->buffer->read($maxBytes);
        if ($data !== '' && $this->onConsumed !== null) {
            ($this->onConsumed)(strlen($data));
        }
        if ($this->pressured && $this->buffer->bytes() <= $this->lowWatermark) {
            $this->pressured = false;
            ($this->onRelief)();
        }

        return $data;
    }

    /**
     * Return cumulative received body bytes.
     */
    public function receivedBytes(): int
    {
        return $this->received;
    }

    /**
     * Return trailers after body completion when available.
     */
    public function trailers(): ?Headers
    {
        return $this->trailers;
    }

    private static function invokeObserver(Closure $callback): void
    {
        try {
            $callback();
        } catch (Throwable) {
            // Runtime-owned cancellation observers must not destabilize protocol cleanup.
        }
    }

    private function invoke(?Closure $callback): void
    {
        if ($callback === null) {
            return;
        }
        $callback($this);
    }

    private function notifyData(): void
    {
        if ($this->dataCallback === null) {
            return;
        }
        if ($this->dataNotifying) {
            $this->dataNotificationPending = true;

            return;
        }

        $this->dataNotifying = true;
        try {
            do {
                $this->dataNotificationPending = false;
                $this->invoke($this->dataCallback);
            } while ($this->dataNotificationPending && !$this->cancelled);
        } finally {
            $this->dataNotifying = false;
        }
    }

    private function invokeCancelObservers(): void
    {
        $observers = $this->cancelObservers;
        $this->cancelObservers = [];
        foreach ($observers as $observer) {
            self::invokeObserver($observer);
        }
    }
}
