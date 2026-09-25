<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Runtime\Host;

use Closure;
use Infocyph\Runwire\Http\Headers;
use Infocyph\Runwire\Http\Internal\ResponseSemantics;
use Infocyph\Runwire\Http\Internal\ResponseTerminalState;
use Infocyph\Runwire\Http\ResponseWriterInterface;
use Infocyph\Runwire\Network\Enum\WriteState;
use Infocyph\Runwire\Network\WriteResult;
use InvalidArgumentException;
use LogicException;

/**
 * Streams bounded HTTP responses through a RoadRunner session.
 */
final class RoadRunnerResponseWriter implements ResponseWriterInterface
{
    private readonly int $maxBodyBytes;

    private int $bodyBytes = 0;

    private readonly ResponseTerminalState $terminal;

    private ?int $contentLength = null;

    private bool $ended = false;

    private Headers $headers;

    private bool $started = false;

    private int $status = 200;

    /**
     * Creates a RoadRunner response writer with an optional HEAD body suppression mode.
     */
    public function __construct(
        private readonly RoadRunnerSessionInterface $session,
        int $maxBodyBytes,
        private readonly bool $headRequest = false,
    ) {
        if ($maxBodyBytes < 1) {
            throw new InvalidArgumentException('Maximum RoadRunner response body size must be positive.');
        }

        $this->headers = new Headers();
        $this->maxBodyBytes = $maxBodyBytes;
        $this->terminal = new ResponseTerminalState();
    }

    /**
     * Finishes the response, optionally writing a final body chunk.
     */
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
            if ($this->contentLength !== null && $this->bodyBytes + $length > $this->contentLength) {
                throw new LogicException('HTTP response body exceeds declared Content-Length.');
            }
            if ($length > $this->maxBodyBytes - $this->bodyBytes) {
                return new WriteResult(WriteState::REJECTED_LIMIT, 0);
            }

            $this->bodyBytes += $length;
            $this->assertCompleteLength();
            $this->finish($finalChunk);

            return new WriteResult(WriteState::ACCEPTED, 0);
        }

        $this->assertCompleteLength();
        $this->finish('');

        return new WriteResult(WriteState::ACCEPTED, 0);
    }

    /**
     * Reports whether the response has ended.
     */
    public function isEnded(): bool
    {
        return $this->ended;
    }

    /**
     * Reports whether response status and headers have been started.
     */
    public function isStarted(): bool
    {
        return $this->started;
    }

    /**
     * Registers an immediate drain callback for this non-buffering writer.
     */
    public function onDrain(callable $callback): ResponseWriterInterface
    {
        if (!$this->ended) {
            Closure::fromCallable($callback)($this);
        }

        return $this;
    }

    /**
     * Registers a callback invoked when response ownership becomes terminal.
     */
    public function onTerminal(callable $callback): self
    {
        $this->terminal->observe($this, $callback);

        return $this;
    }

    /**
     * Starts the response with status and optional headers.
     */
    public function start(int $status = 200, ?Headers $headers = null): WriteResult
    {
        if ($this->ended) {
            return new WriteResult(WriteState::CLOSED, 0);
        }
        ResponseSemantics::assertFinalStatus($status);
        if ($this->started) {
            return new WriteResult(WriteState::ACCEPTED, 0);
        }

        $this->headers = $headers ?? new Headers();
        $this->contentLength = ResponseSemantics::contentLength($this->headers);
        ResponseSemantics::assertContentLength($status, $this->contentLength);
        $this->started = true;
        $this->status = $status;

        return new WriteResult(WriteState::ACCEPTED, 0);
    }

    /**
     * Writes one response body chunk through the RoadRunner session.
     */
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
        if ($this->contentLength !== null && $this->bodyBytes + $length > $this->contentLength) {
            throw new LogicException('HTTP response body exceeds declared Content-Length.');
        }
        if ($length > $this->maxBodyBytes - $this->bodyBytes) {
            return new WriteResult(WriteState::REJECTED_LIMIT, 0);
        }

        $this->session->respond($this->status, $chunk, $this->headerMap(), false);
        $this->bodyBytes += $length;

        return new WriteResult(WriteState::ACCEPTED, 0);
    }

    private function assertCompleteLength(): void
    {
        if ($this->suppressesBody() || $this->contentLength === null || $this->bodyBytes === $this->contentLength) {
            return;
        }

        throw new LogicException('HTTP response body is shorter than declared Content-Length.');
    }

    private function ensureStarted(): WriteResult
    {
        return $this->started ? new WriteResult(WriteState::ACCEPTED, 0) : $this->start();
    }

    private function finish(string $body): void
    {
        $this->session->respond($this->status, $body, $this->headerMap(), true);
        $this->ended = true;
        $this->terminal->terminate($this);
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
        return ResponseSemantics::suppressesBody($this->headRequest, $this->status);
    }
}
