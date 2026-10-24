<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Http2;

use Infocyph\Runwire\Http\Http2\Enum\FrameType;
use InvalidArgumentException;

/**
 * Represents one validated HTTP/2 frame.
 */
final readonly class Frame
{
    /**
     * Create an HTTP/2 frame.
     */
    public function __construct(
        public int $type,
        public int $flags,
        public int $streamId,
        public string $payload = '',
    ) {
        if ($type < 0 || $type > 0xFF) {
            throw new InvalidArgumentException('HTTP/2 frame type must fit in one byte.');
        }
        if ($flags < 0 || $flags > 0xFF) {
            throw new InvalidArgumentException('HTTP/2 frame flags must fit in one byte.');
        }
        if ($streamId < 0 || $streamId > 0x7FFF_FFFF) {
            throw new InvalidArgumentException('HTTP/2 stream ID must be a 31-bit unsigned integer.');
        }
        if (strlen($payload) > 0xFF_FFFF) {
            throw new InvalidArgumentException('HTTP/2 frame payload exceeds the protocol maximum.');
        }
    }

    /**
     * Determine whether the supplied flag bits are set.
     */
    public function hasFlag(int $flag): bool
    {
        return ($this->flags & $flag) === $flag;
    }

    /**
     * Return the known frame type when this identifier is standardized.
     */
    public function knownType(): ?FrameType
    {
        return FrameType::tryFrom($this->type);
    }
}
