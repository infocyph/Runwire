<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Internal;

use Closure;
use Infocyph\Runwire\Http\Headers;
use Infocyph\Runwire\Http\ResponseWriterInterface;
use Infocyph\Runwire\Network\WriteResult;
use Infocyph\Runwire\Network\WriteState;
use InvalidArgumentException;

final class CallbackResponseWriter implements ResponseWriterInterface
{
    /** @var Closure(): void */
    private readonly Closure $endCallback;

    private readonly int $maxBodyBytes;

    /** @var Closure(int, Headers): void */
    private readonly Closure $startCallback;

    /** @var Closure(string): void */
    private readonly Closure $writeCallback;

    private int $bodyBytes = 0;

    private bool $ended = false;

    private int $status = 200;

    private bool $started = false;

    /**
     * @param callable(int, Headers): void $startCallback
     * @param callable(string): void $writeCallback
     * @param callable(): void $endCallback
     */
    public function __construct(
        callable $startCallback,
        callable $writeCallback,
        callable $endCallback,
        int $maxBodyBytes,
        bool $headRequest = false,
    ) {
        if ($maxBodyBytes < 1) {
            throw new InvalidArgumentException('Maximum host response body size must be positive.');
        }

        $this->endCallback = Closure::fromCallable($endCallback);
        $this->maxBodyBytes = $headRequest ? 0 : $maxBodyBytes;
        $this->startCallback = Closure::fromCallable($startCallback);
        $this->writeCallback = Closure::fromCallable($writeCallback);
    }

    public function end(string $finalChunk = ''): WriteResult
    {
        if ($this->ended) {
            return new WriteResult(WriteState::CLOSED, 0);
        }

        $result = $finalChunk === '' ? $this->ensureStarted() : $this->write($finalChunk);
        if (!$result->accepted()) {
            $this->ended = true;
            ($this->endCallback)();

            return $result;
        }

        $this->ended = true;
        ($this->endCallback)();

        return new WriteResult(WriteState::ACCEPTED, 0);
    }

    public function isEnded(): bool
    {
        return $this->ended;
    }

    public function isStarted(): bool
    {
        return $this->started;
    }

    public function onDrain(callable $callback): ResponseWriterInterface
    {
        if (!$this->ended) {
            Closure::fromCallable($callback)($this);
        }

        return $this;
    }

    public function start(int $status = 200, ?Headers $headers = null): WriteResult
    {
        if ($this->ended) {
            return new WriteResult(WriteState::CLOSED, 0);
        }
        if ($status < 100 || $status > 599) {
            throw new InvalidArgumentException('HTTP response status must be between 100 and 599.');
        }
        if ($this->started) {
            return new WriteResult(WriteState::ACCEPTED, 0);
        }

        $this->status = $status;
        $this->started = true;
        ($this->startCallback)($status, $headers ?? new Headers());

        return new WriteResult(WriteState::ACCEPTED, 0);
    }

    public function write(string $chunk): WriteResult
    {
        if ($this->ended) {
            return new WriteResult(WriteState::CLOSED, 0);
        }

        $started = $this->ensureStarted();
        if (!$started->accepted() || $chunk === '' || $this->suppressesBody()) {
            return $started;
        }

        $length = strlen($chunk);
        if ($length > $this->maxBodyBytes - $this->bodyBytes) {
            return new WriteResult(WriteState::REJECTED_LIMIT, 0);
        }

        ($this->writeCallback)($chunk);
        $this->bodyBytes += $length;

        return new WriteResult(WriteState::ACCEPTED, 0);
    }

    private function ensureStarted(): WriteResult
    {
        return $this->started ? new WriteResult(WriteState::ACCEPTED, 0) : $this->start();
    }

    private function suppressesBody(): bool
    {
        return $this->maxBodyBytes === 0
            || ($this->status >= 100 && $this->status < 200)
            || $this->status === 204
            || $this->status === 304;
    }
}
