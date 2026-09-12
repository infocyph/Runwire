<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Process\Internal;

use Closure;
use Infocyph\Runwire\Process\Enum\IoMode;

final class OutputSink
{
    private int $bytes = 0;

    private string $capture = '';

    private bool $truncated = false;

    public function __construct(
        private readonly IoMode $mode,
        private readonly ?Closure $consumer,
    ) {}

    public function bytes(): int
    {
        return $this->bytes;
    }

    public function capture(): string
    {
        return $this->capture;
    }

    public function consume(string $chunk, int $acceptedBytes): void
    {
        $length = strlen($chunk);
        $this->bytes += $length;

        if ($acceptedBytes < $length) {
            $this->truncated = true;
        }

        if ($acceptedBytes <= 0) {
            return;
        }

        $accepted = $acceptedBytes === $length ? $chunk : substr($chunk, 0, $acceptedBytes);

        if ($this->mode === IoMode::CAPTURE) {
            $this->capture .= $accepted;

            return;
        }

        if ($this->mode === IoMode::STREAM && $this->consumer !== null) {
            ($this->consumer)($accepted);
        }
    }

    public function truncated(): bool
    {
        return $this->truncated;
    }
}
