<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Internal;

use Closure;
use Infocyph\Runwire\Http\Headers;
use Infocyph\Runwire\Http\RequestBodyInterface;
use InvalidArgumentException;

final class BufferedRequestBody implements RequestBodyInterface
{
    private int $offset = 0;

    private readonly Headers $trailers;

    public function __construct(private readonly string $body, ?Headers $trailers = null)
    {
        $this->trailers = $trailers ?? new Headers();
    }

    public function bufferedBytes(): int
    {
        return strlen($this->body) - $this->offset;
    }

    public function eof(): bool
    {
        return $this->offset >= strlen($this->body);
    }

    public function onData(callable $callback): RequestBodyInterface
    {
        if (!$this->eof()) {
            Closure::fromCallable($callback)($this);
        }

        return $this;
    }

    public function onEnd(callable $callback): RequestBodyInterface
    {
        Closure::fromCallable($callback)($this);

        return $this;
    }

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

    public function receivedBytes(): int
    {
        return strlen($this->body);
    }

    public function trailers(): ?Headers
    {
        return $this->trailers;
    }
}
