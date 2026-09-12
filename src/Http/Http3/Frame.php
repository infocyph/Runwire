<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Http3;

use Infocyph\Runwire\Http\Http3\Enum\FrameType;

final readonly class Frame
{
    public function __construct(
        public int $type,
        public string $payload = '',
    ) {
        if ($type < 0 || $type > VarIntCodec::MAX_VALUE) {
            throw new \InvalidArgumentException('HTTP/3 frame type must fit a QUIC variable-length integer.');
        }
    }

    public function knownType(): ?FrameType
    {
        return FrameType::tryFrom($this->type);
    }
}
