<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http;

/**
 * Defines incremental request-body consumption, notifications, and trailer access.
 */
interface RequestBodyInterface
{
    /**
     * Return currently buffered unread bytes.
     */
    public function bufferedBytes(): int;

    /**
     * Determine whether the body has ended and all buffered bytes are consumed.
     */
    public function eof(): bool;

    /** @param callable(self): void $callback */
    public function onData(callable $callback): self;

    /** @param callable(self): void $callback */
    public function onEnd(callable $callback): self;

    /**
     * Read up to the requested number of body bytes.
     */
    public function read(int $maxBytes = PHP_INT_MAX): string;

    /**
     * Return cumulative received body bytes.
     */
    public function receivedBytes(): int;

    /**
     * Return request trailers once available.
     */
    public function trailers(): ?Headers;
}
