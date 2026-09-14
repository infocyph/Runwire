<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Process\Internal;

use Closure;
use Infocyph\Runwire\Process\Enum\IoMode;

/**
 * Collects or streams child-process output while tracking truncation.
 */
final class OutputSink
{
    private int $bytes = 0;

    private string $capture = '';

    private bool $truncated = false;

    /**
     * Creates an output sink for the selected I/O mode.
     */
    public function __construct(
        private readonly IoMode $mode,
        private readonly ?Closure $consumer,
    ) {}

    /**
     * Returns the total number of output bytes observed.
     */
    public function bytes(): int
    {
        return $this->bytes;
    }

    /**
     * Returns output captured by CAPTURE mode.
     */
    public function capture(): string
    {
        return $this->capture;
    }

    /**
     * Consumes an output chunk and records the accepted prefix.
     */
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

    /**
     * Reports whether any observed output was truncated.
     */
    public function truncated(): bool
    {
        return $this->truncated;
    }
}
