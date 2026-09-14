<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Http3;

use Infocyph\Runwire\Http\Http3\Enum\FrameType;

/**
 * Represents one validated HTTP/3 frame.
 */
final readonly class Frame
{
    /**
     * Create an HTTP/3 frame from its type identifier and payload.
     */
    public function __construct(
        public int $type,
        public string $payload = '',
    ) {
        if ($type < 0 || $type > VarIntCodec::MAX_VALUE) {
            throw new \InvalidArgumentException('HTTP/3 frame type must fit a QUIC variable-length integer.');
        }
    }

    /**
     * Return the standardized frame type when the identifier is known.
     */
    public function knownType(): ?FrameType
    {
        return FrameType::tryFrom($this->type);
    }
}
