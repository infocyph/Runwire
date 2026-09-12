<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Runtime\Host;

use Closure;
use Infocyph\Runwire\Http\Headers;
use Infocyph\Runwire\Http\ResponseWriterInterface;
use Infocyph\Runwire\Network\Enum\WriteState;
use Infocyph\Runwire\Network\WriteResult;
use InvalidArgumentException;

final class RoadRunnerResponseWriter implements ResponseWriterInterface
{
    private readonly int $maxBodyBytes;

    private int $bodyBytes = 0;

    private bool $ended = false;

    private Headers $headers;

    private bool $started = false;

    private int $status = 200;

    public function __construct(
        private readonly RoadRunnerSessionInterface $session,
        int $maxBodyBytes,
        bool $headRequest = false,
    ) {
        if ($maxBodyBytes < 1) {
            throw new InvalidArgumentException('Maximum RoadRunner response body size must be positive.');
        }

        $this->headers = new Headers();
        $this->maxBodyBytes = $headRequest ? 0 : $maxBodyBytes;
    }

    public function end(string $finalChunk = ''): WriteResult
    {
        if ($this->ended) {
            return new WriteResult(WriteState::CLOSED, 0);
        }

        $started = $this->ensureStarted();
        if (!$started->accepted()) {
            return $started;
        }

        if ($finalChunk !== '' && !$this->suppressesBody()) {
            $length = strlen($finalChunk);
            if ($length > $this->maxBodyBytes - $this->bodyBytes) {
                $this->finish('');

                return new WriteResult(WriteState::REJECTED_LIMIT, 0);
            }

            $this->bodyBytes += $length;
            $this->finish($finalChunk);

            return new WriteResult(WriteState::ACCEPTED, 0);
        }

        $this->finish('');

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

        $this->headers = $headers ?? new Headers();
        $this->started = true;
        $this->status = $status;

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

        $this->session->respond($this->status, $chunk, $this->headerMap(), false);
        $this->bodyBytes += $length;

        return new WriteResult(WriteState::ACCEPTED, 0);
    }

    private function ensureStarted(): WriteResult
    {
        return $this->started ? new WriteResult(WriteState::ACCEPTED, 0) : $this->start();
    }

    private function finish(string $body): void
    {
        $this->session->respond($this->status, $body, $this->headerMap(), true);
        $this->ended = true;
    }

    /** @return array<string, list<string>> */
    private function headerMap(): array
    {
        $headers = [];
        foreach ($this->headers->fields() as $field) {
            $headers[$field->name][] = $field->value;
        }

        return $headers;
    }

    private function suppressesBody(): bool
    {
        return $this->maxBodyBytes === 0
            || ($this->status >= 100 && $this->status < 200)
            || $this->status === 204
            || $this->status === 304;
    }
}
