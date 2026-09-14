<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Protocol;

use InvalidArgumentException;

/**
 * Encodes and incrementally decodes delimiter-terminated text frames.
 */
final class LineCodec implements FrameCodecInterface
{
    private string $buffer = '';

    /**
     * Creates a line codec with the supplied delimiter and frame-size limit.
     */
    public function __construct(
        private readonly string $delimiter = "\n",
        private readonly int $maxFrameBytes = 65_536,
        private readonly bool $includeDelimiter = false,
    ) {
        if ($delimiter === '') {
            throw new InvalidArgumentException('Line delimiter cannot be empty.');
        }
        if (strlen($delimiter) > 32) {
            throw new InvalidArgumentException('Line delimiter cannot exceed 32 bytes.');
        }
        if ($maxFrameBytes <= 0) {
            throw new InvalidArgumentException('Maximum line frame size must be positive.');
        }
    }

    /**
     * Returns bytes currently buffered awaiting a delimiter.
     */
    public function bufferedBytes(): int
    {
        return strlen($this->buffer);
    }

    /**
     * Encodes one frame terminated by the configured delimiter.
     */
    public function encode(string $frame): string
    {
        $payload = $frame;
        if ($this->includeDelimiter && str_ends_with($payload, $this->delimiter)) {
            $payload = substr($payload, 0, -strlen($this->delimiter));
        }
        if (strlen($payload) > $this->maxFrameBytes) {
            throw new CodecException('Line-delimited frame exceeds the configured limit.');
        }
        if (str_contains($payload, $this->delimiter)) {
            throw new CodecException('Line frame payload cannot contain the configured delimiter.');
        }

        return $payload . $this->delimiter;
    }

    /**
     * Decodes up to the requested number of complete line frames.
     */
    public function push(string $bytes, int $maxFrames = 256): array
    {
        if ($maxFrames <= 0) {
            throw new InvalidArgumentException('Maximum frames per decode must be positive.');
        }
        if ($bytes !== '') {
            $this->buffer .= $bytes;
        }

        $frames = [];
        $cursor = 0;
        $delimiterBytes = strlen($this->delimiter);
        while (count($frames) < $maxFrames) {
            $offset = strpos($this->buffer, $this->delimiter, $cursor);
            if ($offset === false) {
                break;
            }

            $payloadBytes = $offset - $cursor;
            if ($payloadBytes > $this->maxFrameBytes) {
                throw new CodecException('Line-delimited frame exceeds the configured limit.');
            }

            $wireEnd = $offset + $delimiterBytes;
            $frames[] = substr(
                $this->buffer,
                $cursor,
                $this->includeDelimiter ? $wireEnd - $cursor : $payloadBytes,
            );
            $cursor = $wireEnd;
        }

        if ($cursor > 0) {
            $this->buffer = substr($this->buffer, $cursor);
        }

        $nextDelimiter = strpos($this->buffer, $this->delimiter);
        if ($nextDelimiter !== false) {
            if ($nextDelimiter > $this->maxFrameBytes) {
                throw new CodecException('Line-delimited frame exceeds the configured limit.');
            }
        } elseif ($this->payloadBytesBeforePossibleDelimiter() > $this->maxFrameBytes) {
            throw new CodecException('Line-delimited frame exceeds the configured limit.');
        }

        return $frames;
    }

    /**
     * Clears buffered partial line data.
     */
    public function reset(): void
    {
        $this->buffer = '';
    }

    private function payloadBytesBeforePossibleDelimiter(): int
    {
        $bufferBytes = strlen($this->buffer);
        $delimiterBytes = strlen($this->delimiter);
        $maximumPrefix = min($delimiterBytes - 1, $bufferBytes);

        for ($prefix = $maximumPrefix; $prefix > 0; --$prefix) {
            if (substr($this->buffer, -$prefix) === substr($this->delimiter, 0, $prefix)) {
                return $bufferBytes - $prefix;
            }
        }

        return $bufferBytes;
    }
}
