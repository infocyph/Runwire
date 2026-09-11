<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http;

interface RequestBodyInterface
{
    public function bufferedBytes(): int;

    public function eof(): bool;

    /** @param callable(self): void $callback */
    public function onData(callable $callback): self;

    /** @param callable(self): void $callback */
    public function onEnd(callable $callback): self;

    public function read(int $maxBytes = PHP_INT_MAX): string;

    public function receivedBytes(): int;

    public function trailers(): ?Headers;
}
