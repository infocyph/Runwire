<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http;

use Infocyph\Runwire\Network\WriteResult;

interface ResponseWriterInterface
{
    public function start(int $status = 200, ?Headers $headers = null): WriteResult;

    public function write(string $chunk): WriteResult;

    public function end(string $finalChunk = ''): WriteResult;

    /** @param callable(self): void $callback */
    public function onDrain(callable $callback): self;

    public function isStarted(): bool;

    public function isEnded(): bool;
}
