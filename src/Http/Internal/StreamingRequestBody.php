<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Internal;

use Closure;
use Infocyph\Runwire\Http\Headers;
use Infocyph\Runwire\Http\RequestBodyInterface;
use Infocyph\Runwire\Network\Internal\ByteQueue;
use InvalidArgumentException;

final class StreamingRequestBody implements RequestBodyInterface
{
    private readonly ByteQueue $buffer;
    private int $received = 0;
    private bool $ended = false;
    private bool $cancelled = false;
    private ?Headers $trailers = null;
    private bool $pressured = false;
    private ?Closure $dataCallback = null;
    private ?Closure $endCallback = null;
    private ?Closure $cancelCallback = null;

    /** @var Closure(): void */
    private readonly Closure $onRelief;
    /** @var Closure(int): void|null */
    private readonly ?Closure $onConsumed;

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

    public function bufferedBytes(): int { return $this->buffer->bytes(); }
    public function receivedBytes(): int { return $this->received; }
    public function eof(): bool { return $this->ended && $this->buffer->isEmpty(); }
    public function cancelled(): bool { return $this->cancelled; }
    public function trailers(): ?Headers { return $this->trailers; }
    public function ended(): bool { return $this->ended; }
    public function pressured(): bool { return $this->pressured; }
    public function capacity(): int { return $this->maxBufferBytes - $this->buffer->bytes(); }

    /** @param callable(RequestBodyInterface): void $callback */
    public function onData(callable $callback): RequestBodyInterface
    {
        $this->dataCallback = Closure::fromCallable($callback);
        if (!$this->buffer->isEmpty()) {
            $this->invoke($this->dataCallback);
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

    /** @param callable(RequestBodyInterface): void $callback */
    public function onCancel(callable $callback): RequestBodyInterface
    {
        $this->cancelCallback = Closure::fromCallable($callback);
        if ($this->cancelled) {
            $this->invoke($this->cancelCallback);
        }
        return $this;
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
        $this->invoke($this->dataCallback);
        if ($this->buffer->bytes() >= $this->highWatermark) {
            $this->pressured = true;
        }
        return !$this->pressured;
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

    /** @internal */
    public function cancel(): void
    {
        if ($this->ended || $this->cancelled) {
            return;
        }
        $this->cancelled = true;
        $discarded = $this->buffer->bytes();
        $this->buffer->clear();
        $this->pressured = false;
        if ($discarded > 0 && $this->onConsumed !== null) {
            ($this->onConsumed)($discarded);
        }
        $this->invoke($this->cancelCallback);
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

    private function invoke(?Closure $callback): void
    {
        if ($callback === null) {
            return;
        }
        $callback($this);
    }
}
