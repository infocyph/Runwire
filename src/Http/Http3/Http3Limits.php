<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Http3;

use InvalidArgumentException;

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
        public float $requestHeaderTimeoutSeconds = 10.0,
        public float $requestBodyIdleTimeoutSeconds = 30.0,
        public float $qpackBlockedTimeoutSeconds = 10.0,
    ) {
        self::assertNonNegative([
            'maxFramePayloadBytes' => $maxFramePayloadBytes,
            'qpackMaxTableCapacity' => $qpackMaxTableCapacity,
            'qpackMaxBlockedStreams' => $qpackMaxBlockedStreams,
            'maxBlockedFieldSectionBytes' => $maxBlockedFieldSectionBytes,
            'maxBlockedRequestStreamBytes' => $maxBlockedRequestStreamBytes,
            'bodyLowWatermarkBytes' => $bodyLowWatermarkBytes,
        ]);
        self::assertPositive([
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
        ]);
        self::assertVarIntCompatible([
            'maxFieldSectionBytes' => $maxFieldSectionBytes,
            'qpackMaxTableCapacity' => $qpackMaxTableCapacity,
            'qpackMaxBlockedStreams' => $qpackMaxBlockedStreams,
        ]);
        self::assertBodyWatermarks(
            $bodyLowWatermarkBytes,
            $bodyHighWatermarkBytes,
            $maxPendingBodyBytesPerStream,
        );
        self::assertResponseWatermarks(
            $responseLowWatermarkBytes,
            $responseHighWatermarkBytes,
            $maxPendingResponseBytesPerStream,
        );
        self::assertResponseLimits(
            $maxPendingResponseBytesPerStream,
            $maxPendingResponseBytesPerConnection,
            $maxQpackEncoderQueueBytes,
            $maxResponseFramePayloadBytes,
        );
        foreach ([
            'requestHeaderTimeoutSeconds' => $requestHeaderTimeoutSeconds,
            'requestBodyIdleTimeoutSeconds' => $requestBodyIdleTimeoutSeconds,
            'qpackBlockedTimeoutSeconds' => $qpackBlockedTimeoutSeconds,
        ] as $name => $value) {
            if (!is_finite($value) || $value <= 0) {
                throw new InvalidArgumentException(sprintf('%s must be finite and positive.', $name));
            }
        }
    }

    private static function assertBodyWatermarks(int $low, int $high, int $maximum): void
    {
        if ($low >= $high || $high > $maximum) {
            throw new InvalidArgumentException(
                'HTTP/3 body watermarks must satisfy 0 <= low < high <= max pending body bytes.',
            );
        }
    }

    /** @param array<string, int> $values */
    private static function assertNonNegative(array $values): void
    {
        foreach ($values as $name => $value) {
            if ($value < 0) {
                throw new InvalidArgumentException(sprintf('%s cannot be negative.', $name));
            }
        }
    }

    /** @param array<string, int> $values */
    private static function assertPositive(array $values): void
    {
        foreach ($values as $name => $value) {
            if ($value <= 0) {
                throw new InvalidArgumentException(sprintf('%s must be positive.', $name));
            }
        }
    }

    private static function assertResponseLimits(
        int $perStream,
        int $perConnection,
        int $qpackQueue,
        int $framePayload,
    ): void {
        if ($perStream > $perConnection) {
            throw new InvalidArgumentException(
                'Per-stream HTTP/3 pending response limit cannot exceed the connection aggregate limit.',
            );
        }
        if ($qpackQueue > $perConnection) {
            throw new InvalidArgumentException(
                'HTTP/3 QPACK encoder queue limit cannot exceed the connection aggregate response limit.',
            );
        }
        if ($framePayload > $perStream) {
            throw new InvalidArgumentException(
                'HTTP/3 response frame payload limit cannot exceed the per-stream pending response limit.',
            );
        }
    }

    private static function assertResponseWatermarks(int $low, int $high, int $maximum): void
    {
        if ($low < 0 || $low >= $high || $high > $maximum) {
            throw new InvalidArgumentException(
                'HTTP/3 response watermarks must satisfy 0 <= low < high <= max pending response bytes.',
            );
        }
    }

    /** @param array<string, int> $values */
    private static function assertVarIntCompatible(array $values): void
    {
        foreach ($values as $name => $value) {
            if ($value > VarIntCodec::MAX_VALUE) {
                throw new InvalidArgumentException(sprintf('%s must fit a QUIC variable-length integer.', $name));
            }
        }
    }
}
