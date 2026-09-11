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
        public int $maxBlockedRequestStreamBytes = 1_048_576,
        public int $maxBodyBytes = 16_777_216,
        public int $maxPendingBodyBytesPerStream = 65_535,
        public int $bodyLowWatermarkBytes = 16_384,
        public int $bodyHighWatermarkBytes = 49_152,
        public int $maxConcurrentRequestStreams = 100,
        public int $maxRequestStreamsPerConnection = 10_000,
        public int $maxPeerUnidirectionalStreamsPerConnection = 1_024,
        public int $maxPendingQpackDecoderBytes = 65_536,
        public int $maxControlStreamBytesPerTick = 65_536,
    ) {
        foreach ([
            'maxFramePayloadBytes' => $maxFramePayloadBytes,
            'maxFieldSectionBytes' => $maxFieldSectionBytes,
            'maxHeaderFields' => $maxHeaderFields,
            'qpackMaxTableCapacity' => $qpackMaxTableCapacity,
            'qpackMaxBlockedStreams' => $qpackMaxBlockedStreams,
            'maxBlockedFieldSectionBytes' => $maxBlockedFieldSectionBytes,
            'maxBlockedRequestStreamBytes' => $maxBlockedRequestStreamBytes,
            'maxBodyBytes' => $maxBodyBytes,
            'maxPendingBodyBytesPerStream' => $maxPendingBodyBytesPerStream,
            'maxConcurrentRequestStreams' => $maxConcurrentRequestStreams,
            'maxRequestStreamsPerConnection' => $maxRequestStreamsPerConnection,
            'maxPeerUnidirectionalStreamsPerConnection' => $maxPeerUnidirectionalStreamsPerConnection,
            'maxPendingQpackDecoderBytes' => $maxPendingQpackDecoderBytes,
            'maxControlStreamBytesPerTick' => $maxControlStreamBytesPerTick,
        ] as $name => $value) {
            if ($value < 0) {
                throw new \InvalidArgumentException(sprintf('%s cannot be negative.', $name));
            }
        }
        foreach ([
            'maxHeaderFields' => $maxHeaderFields,
            'maxBodyBytes' => $maxBodyBytes,
            'maxPendingBodyBytesPerStream' => $maxPendingBodyBytesPerStream,
            'maxConcurrentRequestStreams' => $maxConcurrentRequestStreams,
            'maxRequestStreamsPerConnection' => $maxRequestStreamsPerConnection,
            'maxPeerUnidirectionalStreamsPerConnection' => $maxPeerUnidirectionalStreamsPerConnection,
            'maxPendingQpackDecoderBytes' => $maxPendingQpackDecoderBytes,
            'maxControlStreamBytesPerTick' => $maxControlStreamBytesPerTick,
        ] as $name => $value) {
            if ($value === 0) {
                throw new \InvalidArgumentException(sprintf('%s must be positive.', $name));
            }
        }
        if ($maxConcurrentRequestStreams > $maxRequestStreamsPerConnection) {
            throw new \InvalidArgumentException(
                'HTTP/3 concurrent request stream limit cannot exceed the per-connection stream limit.',
            );
        }
        if ($bodyLowWatermarkBytes < 0
            || $bodyLowWatermarkBytes >= $bodyHighWatermarkBytes
            || $bodyHighWatermarkBytes > $maxPendingBodyBytesPerStream) {
            throw new \InvalidArgumentException(
                'HTTP/3 body watermarks must satisfy 0 <= low < high <= max pending body bytes.',
            );
        }
    }
}
