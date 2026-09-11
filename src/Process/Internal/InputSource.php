<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Process\Internal;

use Closure;
use Infocyph\Runwire\Exception\ProcessException;

final class InputSource
{
    private ?string $string = null;
    private int $offset = 0;
    private mixed $stream = null;
    private ?Closure $producer = null;
    private bool $eof = false;
    private ?bool $streamWasBlocked = null;
    private int $bytesProduced = 0;

    public function __construct(mixed $input, private readonly int $maxBytes)
    {
        if (is_string($input)) {
            $this->string = $input;
            return;
        }

        if (is_resource($input)) {
            $meta = stream_get_meta_data($input);
            if (($meta['stream_type'] ?? '') === '') {
                throw new ProcessException('stdin resource must be a stream.');
            }

            $this->stream = $input;
            $this->streamWasBlocked = (bool) ($meta['blocked'] ?? true);
            @stream_set_blocking($this->stream, false);
            return;
        }

        if (is_callable($input)) {
            $this->producer = Closure::fromCallable($input);
            return;
        }

        $this->eof = true;
    }

    public function isResource(): bool
    {
        return is_resource($this->stream);
    }

    public function resource(): mixed
    {
        return $this->stream;
    }

    public function eof(): bool
    {
        return $this->eof;
    }

    public function pull(int $maxBytes): ?string
    {
        if ($this->eof) {
            return null;
        }

        $chunk = $this->read($maxBytes);
        if ($chunk === null) {
            $this->eof = true;
            return null;
        }

        if ($chunk === '') {
            return '';
        }

        $this->bytesProduced += strlen($chunk);
        if ($this->bytesProduced > $this->maxBytes) {
            throw new ProcessException('stdin exceeds the process policy byte limit.');
        }

        return $chunk;
    }

    public function close(): void
    {
        if (is_resource($this->stream) && $this->streamWasBlocked !== null) {
            @stream_set_blocking($this->stream, $this->streamWasBlocked);
        }
    }

    private function read(int $maxBytes): ?string
    {
        if ($this->string !== null) {
            if ($this->offset >= strlen($this->string)) {
                return null;
            }

            $chunk = substr($this->string, $this->offset, $maxBytes);
            $this->offset += strlen($chunk);
            return $chunk;
        }

        if (is_resource($this->stream)) {
            $chunk = @fread($this->stream, $maxBytes);
            if ($chunk === false) {
                throw new ProcessException('Unable to read process stdin stream.');
            }

            if ($chunk === '' && feof($this->stream)) {
                return null;
            }

            return $chunk;
        }

        if ($this->producer !== null) {
            $chunk = ($this->producer)($maxBytes);
            if ($chunk === null || $chunk === '') {
                return null;
            }

            if (!is_string($chunk)) {
                throw new ProcessException('stdin producer must return a string, empty string or null.');
            }

            if (strlen($chunk) > $maxBytes) {
                throw new ProcessException('stdin producer returned a chunk larger than requested.');
            }

            return $chunk;
        }

        return null;
    }
}
