<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Process\Internal;

use Closure;
use Infocyph\Runwire\Exception\ProcessException;

final class InputSource
{
    private int $bytesProduced = 0;

    private bool $eof = false;

    private int $offset = 0;

    private ?Closure $producer = null;

    private mixed $stream = null;

    private ?bool $streamWasBlocked = null;

    private ?string $string = null;

    public function __construct(mixed $input, private readonly int $maxBytes)
    {
        if (is_string($input)) {
            $this->string = $input;

            return;
        }

        if (is_resource($input)) {
            $meta = stream_get_meta_data($input);
            if ($meta['stream_type'] === '') {
                throw new ProcessException('stdin resource must be a stream.');
            }

            $this->stream = $input;
            $this->streamWasBlocked = self::blockedState($meta);
            if (!stream_set_blocking($this->stream, false)) {
                throw new ProcessException('Unable to make process stdin stream non-blocking.');
            }

            return;
        }

        if (is_callable($input)) {
            $this->producer = Closure::fromCallable($input);

            return;
        }

        $this->eof = true;
    }

    public function close(): void
    {
        if (is_resource($this->stream) && $this->streamWasBlocked !== null) {
            stream_set_blocking($this->stream, $this->streamWasBlocked);
        }
    }

    public function eof(): bool
    {
        return $this->eof;
    }

    public function isResource(): bool
    {
        return is_resource($this->stream);
    }

    public function pull(int $maxBytes): ?string
    {
        if ($maxBytes <= 0) {
            throw new ProcessException('stdin pull size must be positive.');
        }
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

    public function resource(): mixed
    {
        return $this->stream;
    }

    /** @param array<string, mixed> $metadata */
    private static function blockedState(array $metadata): ?bool
    {
        $blocked = $metadata['blocked'] ?? null;

        return is_bool($blocked) ? $blocked : null;
    }

    private function read(int $maxBytes): ?string
    {
        if ($this->string !== null) {
            return $this->readString($maxBytes);
        }
        if (is_resource($this->stream)) {
            return $this->readStream($maxBytes);
        }
        if ($this->producer !== null) {
            return $this->readProducer($maxBytes);
        }

        return null;
    }

    private function readProducer(int $maxBytes): ?string
    {
        if ($this->producer === null) {
            return null;
        }
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

    private function readStream(int $maxBytes): ?string
    {
        if (!is_resource($this->stream)) {
            return null;
        }
        $length = max(1, $maxBytes);
        $chunk = fread($this->stream, $length);
        if ($chunk === false) {
            throw new ProcessException('Unable to read process stdin stream.');
        }
        if ($chunk === '' && feof($this->stream)) {
            return null;
        }

        return $chunk;
    }

    private function readString(int $maxBytes): ?string
    {
        if ($this->string === null || $this->offset >= strlen($this->string)) {
            return null;
        }
        $chunk = substr($this->string, $this->offset, $maxBytes);
        $this->offset += strlen($chunk);

        return $chunk;
    }
}
