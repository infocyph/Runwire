<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Http3;

/**
 * Defines bounded HTTP/3, QPACK, stream, buffering, and pump resource limits.
 */
final readonly class Http3Limits
{
    /**
     * Create and validate HTTP/3 resource limits.
     */
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
        public int $maxControlBytesPerTick = 65_536,
        public int $maxPendingResponseBytesPerStream = 1_048_576,
        public int $responseLowWatermarkBytes = 262_144,
        public int $responseHighWatermarkBytes = 786_432,
        public int $maxPendingResponseBytesPerConnection = 8_388_608,
        public int $maxQpackEncoderQueueBytes = 1_048_576,
        public int $maxResponseFramePayloadBytes = 16_384,
        public int $maxWritesPerFlush = 128,
        public int $maxConnectionsAcceptedPerPump = 64,
        public int $maxStreamsAcceptedPerPump = 64,
        public int $maxReadsPerPump = 256,
        public int $maxInboundBytesPerPump = 262_144,
        public int $streamReadChunkBytes = 16_384,
    ) {
        foreach ([
            'maxFramePayloadBytes' => $maxFramePayloadBytes,
            'qpackMaxTableCapacity' => $qpackMaxTableCapacity,
            'qpackMaxBlockedStreams' => $qpackMaxBlockedStreams,
            'maxBlockedFieldSectionBytes' => $maxBlockedFieldSectionBytes,
            'maxBlockedRequestStreamBytes' => $maxBlockedRequestStreamBytes,
            'bodyLowWatermarkBytes' => $bodyLowWatermarkBytes,
        ] as $name => $value) {
            if ($value < 0) {
                throw new \InvalidArgumentException(sprintf('%s cannot be negative.', $name));
            }
        }
        foreach ([
            'maxFieldSectionBytes' => $maxFieldSectionBytes,
            'maxHeaderFields' => $maxHeaderFields,
            'maxBodyBytes' => $maxBodyBytes,
            'maxPendingBodyBytesPerStream' => $maxPendingBodyBytesPerStream,
            'maxConcurrentRequestStreams' => $maxConcurrentRequestStreams,
            'maxRequestStreamsPerConnection' => $maxRequestStreamsPerConnection,
            'maxPeerUnidirectionalStreamsPerConnection' => $maxPeerUnidirectionalStreamsPerConnection,
            'maxPendingQpackDecoderBytes' => $maxPendingQpackDecoderBytes,
            'maxControlBytesPerTick' => $maxControlBytesPerTick,
            'maxPendingResponseBytesPerStream' => $maxPendingResponseBytesPerStream,
            'maxPendingResponseBytesPerConnection' => $maxPendingResponseBytesPerConnection,
            'maxQpackEncoderQueueBytes' => $maxQpackEncoderQueueBytes,
            'maxResponseFramePayloadBytes' => $maxResponseFramePayloadBytes,
            'maxWritesPerFlush' => $maxWritesPerFlush,
            'maxConnectionsAcceptedPerPump' => $maxConnectionsAcceptedPerPump,
            'maxStreamsAcceptedPerPump' => $maxStreamsAcceptedPerPump,
            'maxReadsPerPump' => $maxReadsPerPump,
            'maxInboundBytesPerPump' => $maxInboundBytesPerPump,
            'streamReadChunkBytes' => $streamReadChunkBytes,
        ] as $name => $value) {
            if ($value <= 0) {
                throw new \InvalidArgumentException(sprintf('%s must be positive.', $name));
            }
        }
        foreach ([
            'maxFieldSectionBytes' => $maxFieldSectionBytes,
            'qpackMaxTableCapacity' => $qpackMaxTableCapacity,
            'qpackMaxBlockedStreams' => $qpackMaxBlockedStreams,
        ] as $name => $value) {
            if ($value > VarIntCodec::MAX_VALUE) {
                throw new \InvalidArgumentException(sprintf('%s must fit a QUIC variable-length integer.', $name));
            }
        }
        if ($bodyLowWatermarkBytes >= $bodyHighWatermarkBytes
            || $bodyHighWatermarkBytes > $maxPendingBodyBytesPerStream) {
            throw new \InvalidArgumentException(
                'HTTP/3 body watermarks must satisfy 0 <= low < high <= max pending body bytes.',
            );
        }
        if ($responseLowWatermarkBytes < 0
            || $responseLowWatermarkBytes >= $responseHighWatermarkBytes
            || $responseHighWatermarkBytes > $maxPendingResponseBytesPerStream) {
            throw new \InvalidArgumentException(
                'HTTP/3 response watermarks must satisfy 0 <= low < high <= max pending response bytes.',
            );
        }
        if ($maxPendingResponseBytesPerStream > $maxPendingResponseBytesPerConnection) {
            throw new \InvalidArgumentException(
                'Per-stream HTTP/3 pending response limit cannot exceed the connection aggregate limit.',
            );
        }
        if ($maxQpackEncoderQueueBytes > $maxPendingResponseBytesPerConnection) {
            throw new \InvalidArgumentException(
                'HTTP/3 QPACK encoder queue limit cannot exceed the connection aggregate response limit.',
            );
        }
        if ($maxResponseFramePayloadBytes > $maxPendingResponseBytesPerStream) {
            throw new \InvalidArgumentException(
                'HTTP/3 response frame payload limit cannot exceed the per-stream pending response limit.',
            );
        }
    }
}
