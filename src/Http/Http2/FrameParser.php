<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Http2;

use Infocyph\Runwire\Http\Http2\Enum\ErrorCode;
use Infocyph\Runwire\Http\Http2\Internal\ConnectionError;
use Infocyph\Runwire\Network\Internal\ByteBudget;
use Infocyph\Runwire\Network\Internal\ByteQueue;

/**
 * Incrementally parses HTTP/2 frames from arbitrary byte chunks.
 */
final class FrameParser
{
    private readonly ByteQueue $buffer;

    /** @var array{length: int, type: int, flags: int, stream_id: int}|null */
    private ?array $pending = null;

    /**
     * Create a frame parser with an inbound frame-size limit.
     */
    public function __construct(private int $maxFrameSize = 16_384, ?ByteBudget $budget = null)
    {
        $this->validateMaxFrameSize($maxFrameSize);
        $this->buffer = new ByteQueue($budget);
    }

    /**
     * Return the number of buffered unparsed bytes.
     */
    public function bufferedBytes(): int
    {
        return $this->buffer->bytes();
    }

    /**
     * Report whether a complete buffered frame can be consumed without more I/O.
     */
    public function hasCompleteFrame(): bool
    {
        if ($this->pending === null && !$this->readHeader()) {
            return false;
        }

        return $this->pending !== null && $this->buffer->bytes() >= $this->pending['length'];
    }

    /** @return list<Frame> */
    public function push(string $bytes, int $maxFrames = PHP_INT_MAX): array
    {
        if ($bytes !== '') {
            $this->buffer->append($bytes);
        }

        $frames = [];
        while (count($frames) < $maxFrames) {
            if ($this->pending === null && !$this->readHeader()) {
                break;
            }

            $pending = $this->pending;
            if ($pending === null) {
                break;
            }
            if ($this->buffer->bytes() < $pending['length']) {
                break;
            }

            $frames[] = new Frame(
                type: $pending['type'],
                flags: $pending['flags'],
                streamId: $pending['stream_id'],
                payload: $this->buffer->read($pending['length']),
            );
            $this->pending = null;
        }

        return $frames;
    }

    /**
     * Update the maximum accepted inbound frame size.
     */
    public function setMaxFrameSize(int $bytes): void
    {
        $this->validateMaxFrameSize($bytes);
        $this->maxFrameSize = $bytes;
    }

    private function readHeader(): bool
    {
        if ($this->buffer->bytes() < 9) {
            return false;
        }

        $header = $this->buffer->read(9);
        $length = (ord($header[0]) << 16) | (ord($header[1]) << 8) | ord($header[2]);
        if ($length > $this->maxFrameSize) {
            throw new ConnectionError(ErrorCode::FRAME_SIZE_ERROR, 'HTTP/2 frame exceeds the configured inbound frame limit.');
        }

        $decoded = unpack('Nstream', substr($header, 5, 4));
        $streamWord = $decoded === false ? null : ($decoded['stream'] ?? null);
        if (!is_int($streamWord)) {
            throw new ConnectionError(ErrorCode::PROTOCOL_ERROR, 'Unable to decode HTTP/2 frame stream identifier.');
        }

        $this->pending = [
            'length' => $length,
            'type' => ord($header[3]),
            'flags' => ord($header[4]),
            'stream_id' => $streamWord & 0x7FFF_FFFF,
        ];

        return true;
    }

    private function validateMaxFrameSize(int $bytes): void
    {
        if ($bytes < 16_384 || $bytes > 0xFF_FFFF) {
            throw new \InvalidArgumentException('HTTP/2 frame size must be between 16384 and 16777215.');
        }
    }
}
