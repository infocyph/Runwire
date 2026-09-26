<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Http3;

use Infocyph\Runwire\Http\Http3\Enum\ErrorCode;

/**
 * Incrementally parses bounded HTTP/3 frames from arbitrary byte chunks.
 */
final class FrameParser
{
    private string $buffer = '';

    /**
     * Create a parser with the maximum accepted frame payload size.
     */
    public function __construct(private readonly int $maxFramePayloadBytes = 1_048_576)
    {
        if ($maxFramePayloadBytes < 0) {
            throw new \InvalidArgumentException('HTTP/3 frame payload limit cannot be negative.');
        }
    }

    /**
     * Append transport bytes without forcing all complete frames to materialize.
     */
    public function append(string $bytes): void
    {
        if ($bytes !== '') {
            $this->buffer .= $bytes;
        }
    }

    /**
     * Return the number of currently buffered unparsed bytes.
     */
    public function bufferedBytes(): int
    {
        return strlen($this->buffer);
    }

    /** @return list<Frame> */
    public function push(string $bytes): array
    {
        $this->append($bytes);
        $frames = [];
        while (($frame = $this->shift()) !== null) {
            $frames[] = $frame;
        }

        return $frames;
    }

    /**
     * Remove and return one complete frame when available.
     */
    public function shift(): ?Frame
    {
        $decoded = $this->nextFrame(0);
        if ($decoded === null) {
            if (strlen($this->buffer) > $this->maxFramePayloadBytes + 16) {
                throw new Http3Exception(ErrorCode::EXCESSIVE_LOAD, 'HTTP/3 frame buffering exceeds configured limit.');
            }

            return null;
        }

        [$frame, $offset] = $decoded;
        $this->buffer = substr($this->buffer, $offset);

        return $frame;
    }

    /** @return array{0: Frame, 1: int}|null */
    private function nextFrame(int $offset): ?array
    {
        $type = VarIntCodec::tryDecode($this->buffer, $offset);
        if ($type === null) {
            return null;
        }
        [$frameType, $afterType] = $type;

        $length = VarIntCodec::tryDecode($this->buffer, $afterType);
        if ($length === null) {
            return null;
        }
        [$payloadLength, $payloadOffset] = $length;
        if ($payloadLength > $this->maxFramePayloadBytes) {
            throw new Http3Exception(ErrorCode::EXCESSIVE_LOAD, 'HTTP/3 frame exceeds configured payload limit.');
        }
        if (strlen($this->buffer) - $payloadOffset < $payloadLength) {
            return null;
        }

        return [
            new Frame($frameType, substr($this->buffer, $payloadOffset, $payloadLength)),
            $payloadOffset + $payloadLength,
        ];
    }
}
