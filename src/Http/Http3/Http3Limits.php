<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Http3;

final readonly class Http3Limits
{
    public function __construct(
        public int $maxFramePayloadBytes = 1_048_576,
        public int $maxFieldSectionBytes = 65_536,
        public int $maxHeaderFields = 128,
        public int $qpackMaxTableCapacity = 65_536,
        public int $qpackMaxBlockedStreams = 32,
        public int $maxBlockedFieldSectionBytes = 1_048_576,
        public int $maxControlStreamBytesPerTick = 65_536,
    ) {
        foreach ([
            'maxFramePayloadBytes' => $maxFramePayloadBytes,
            'maxFieldSectionBytes' => $maxFieldSectionBytes,
            'maxHeaderFields' => $maxHeaderFields,
            'qpackMaxTableCapacity' => $qpackMaxTableCapacity,
            'qpackMaxBlockedStreams' => $qpackMaxBlockedStreams,
            'maxBlockedFieldSectionBytes' => $maxBlockedFieldSectionBytes,
            'maxControlStreamBytesPerTick' => $maxControlStreamBytesPerTick,
        ] as $name => $value) {
            if ($value < 0) {
                throw new \InvalidArgumentException(sprintf('%s cannot be negative.', $name));
            }
        }
        if ($maxHeaderFields === 0 || $maxControlStreamBytesPerTick === 0) {
            throw new \InvalidArgumentException('HTTP/3 header-count and control-stream work limits must be positive.');
        }
    }
}
