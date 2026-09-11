<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Http3;

final class FrameParser
{
    private string $buffer = '';

    public function __construct(private readonly int $maxFramePayloadBytes = 1_048_576)
    {
        if ($maxFramePayloadBytes < 0) {
            throw new \InvalidArgumentException('HTTP/3 frame payload limit cannot be negative.');
        }
    }

    public function bufferedBytes(): int
    {
        return strlen($this->buffer);
    }

    /** @return list<Frame> */
    public function push(string $bytes): array
    {
        if ($bytes !== '') {
            $this->buffer .= $bytes;
        }

        $frames = [];
        $offset = 0;
        while (($decoded = $this->nextFrame($offset)) !== null) {
            [$frame, $offset] = $decoded;
            $frames[] = $frame;
        }

        if ($offset > 0) {
            $this->buffer = substr($this->buffer, $offset);
        }
        if (strlen($this->buffer) > $this->maxFramePayloadBytes + 16) {
            throw new Http3Exception(ErrorCode::EXCESSIVE_LOAD, 'HTTP/3 frame buffering exceeds configured limit.');
        }

        return $frames;
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
