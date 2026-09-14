<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Internal;

use Closure;
use Infocyph\Runwire\Http\Headers;
use Infocyph\Runwire\Http\RequestBodyInterface;
use InvalidArgumentException;

/**
 * Exposes a fully buffered request body through the streaming body contract.
 */
final class BufferedRequestBody implements RequestBodyInterface
{
    private readonly Headers $trailers;

    private int $offset = 0;

    /**
     * Create a buffered request body with optional trailers.
     */
    public function __construct(private readonly string $body, ?Headers $trailers = null)
    {
        $this->trailers = $trailers ?? new Headers();
    }

    /**
     * Return unread buffered body bytes.
     */
    public function bufferedBytes(): int
    {
        return strlen($this->body) - $this->offset;
    }

    /**
     * Determine whether all buffered body bytes have been consumed.
     */
    public function eof(): bool
    {
        return $this->offset >= strlen($this->body);
    }

    /**
     * Register a data callback and invoke it immediately when unread bytes exist.
     */
    public function onData(callable $callback): RequestBodyInterface
    {
        if (!$this->eof()) {
            Closure::fromCallable($callback)($this);
        }

        return $this;
    }

    /**
     * Register an end callback and invoke it immediately for a buffered body.
     */
    public function onEnd(callable $callback): RequestBodyInterface
    {
        Closure::fromCallable($callback)($this);

        return $this;
    }

    /**
     * Read up to the requested number of bytes from the current offset.
     */
    public function read(int $maxBytes = PHP_INT_MAX): string
    {
        if ($maxBytes < 0) {
            throw new InvalidArgumentException('Maximum body read length cannot be negative.');
        }
        if ($maxBytes === 0 || $this->eof()) {
            return '';
        }

        $data = substr($this->body, $this->offset, $maxBytes);
        $this->offset += strlen($data);

        return $data;
    }

    /**
     * Return the total body bytes received.
     */
    public function receivedBytes(): int
    {
        return strlen($this->body);
    }

    /**
     * Return request trailers.
     */
    public function trailers(): Headers
    {
        return $this->trailers;
    }
}
