<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http;

use Infocyph\Runwire\Network\WriteResult;

/**
 * Defines streaming HTTP response lifecycle and backpressure operations.
 */
interface ResponseWriterInterface
{
    /**
     * End the response, optionally with one final body chunk.
     */
    public function end(string $finalChunk = ''): WriteResult;

    /**
     * Determine whether the response has ended.
     */
    public function isEnded(): bool;

    /**
     * Determine whether response headers have been started.
     */
    public function isStarted(): bool;

    /** @param callable(self): void $callback */
    public function onDrain(callable $callback): self;

    /**
     * Register a callback invoked exactly once when response ownership becomes terminal.
     *
     * @param callable(self): void $callback
     */
    public function onTerminal(callable $callback): self;

    /**
     * Start the response with status and headers.
     */
    public function start(int $status = 200, ?Headers $headers = null): WriteResult;

    /**
     * Write a response body chunk.
     */
    public function write(string $chunk): WriteResult;
}
