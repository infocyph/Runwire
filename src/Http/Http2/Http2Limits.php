<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Http2;

use InvalidArgumentException;

/**
 * Defines bounded resource, flow-control, buffering, and timeout limits for HTTP/2.
 */
final readonly class Http2Limits
{
    /**
     * Create and validate HTTP/2 connection limits.
     */
    public function __construct(
        public int $maxInboundFrameSize = 16_384,
        public int $maxHeaderBlockBytes = 65_536,
        public int $maxContinuationFrames = 32,
        public int $maxHeaderListBytes = 65_536,
        public int $maxHeaderCount = 100,
        public int $maxDynamicTableBytes = 4_096,
        public int $maxConcurrentStreams = 100,
        public int $maxStreamsPerConnection = 10_000,
        public int $maxBodyBytes = 16_777_216,
        public int $maxPendingBodyBytesPerStream = 65_535,
        public int $bodyLowWatermarkBytes = 16_384,
        public int $bodyHighWatermarkBytes = 49_152,
        public int $maxPendingResponseBytesPerStream = 1_048_576,
        public int $responseLowWatermarkBytes = 262_144,
        public int $responseHighWatermarkBytes = 786_432,
        public int $maxPendingResponseBytesPerConnection = 8_388_608,
        public int $maxWireQueueBytes = 1_048_576,
        public int $maxFramesPerTurn = 128,
        public int $maxFramesPerFlush = 128,
        public int $maxControlFramesPerSecond = 1_000,
        public float $headerBlockTimeoutSeconds = 10.0,
        public float $streamIdleTimeoutSeconds = 60.0,
        public float $drainTimeoutSeconds = 30.0,
    ) {
        if ($maxInboundFrameSize < 16_384 || $maxInboundFrameSize > 0xFF_FFFF) {
            throw new InvalidArgumentException('HTTP/2 maximum inbound frame size must be between 16384 and 16777215.');
        }
        foreach ([
            'maxHeaderBlockBytes' => $maxHeaderBlockBytes,
            'maxContinuationFrames' => $maxContinuationFrames,
            'maxHeaderListBytes' => $maxHeaderListBytes,
            'maxHeaderCount' => $maxHeaderCount,
            'maxDynamicTableBytes' => $maxDynamicTableBytes,
            'maxConcurrentStreams' => $maxConcurrentStreams,
            'maxStreamsPerConnection' => $maxStreamsPerConnection,
            'maxBodyBytes' => $maxBodyBytes,
            'maxPendingBodyBytesPerStream' => $maxPendingBodyBytesPerStream,
            'maxPendingResponseBytesPerStream' => $maxPendingResponseBytesPerStream,
            'maxPendingResponseBytesPerConnection' => $maxPendingResponseBytesPerConnection,
            'maxWireQueueBytes' => $maxWireQueueBytes,
            'maxFramesPerTurn' => $maxFramesPerTurn,
            'maxFramesPerFlush' => $maxFramesPerFlush,
            'maxControlFramesPerSecond' => $maxControlFramesPerSecond,
        ] as $name => $value) {
            if ($value <= 0) {
                throw new InvalidArgumentException(sprintf('%s must be positive.', $name));
            }
        }
        if ($bodyLowWatermarkBytes < 0
            || $bodyLowWatermarkBytes >= $bodyHighWatermarkBytes
            || $bodyHighWatermarkBytes > $maxPendingBodyBytesPerStream) {
            throw new InvalidArgumentException('HTTP/2 body watermarks must satisfy 0 <= low < high <= max pending body bytes.');
        }
        if ($responseLowWatermarkBytes < 0
            || $responseLowWatermarkBytes >= $responseHighWatermarkBytes
            || $responseHighWatermarkBytes > $maxPendingResponseBytesPerStream) {
            throw new InvalidArgumentException('HTTP/2 response watermarks must satisfy 0 <= low < high <= max pending response bytes.');
        }
        if ($maxPendingResponseBytesPerStream > $maxPendingResponseBytesPerConnection) {
            throw new InvalidArgumentException('Per-stream pending response limit cannot exceed the connection aggregate limit.');
        }
        foreach ([
            'headerBlockTimeoutSeconds' => $headerBlockTimeoutSeconds,
            'streamIdleTimeoutSeconds' => $streamIdleTimeoutSeconds,
            'drainTimeoutSeconds' => $drainTimeoutSeconds,
        ] as $name => $value) {
            if (!is_finite($value) || $value <= 0) {
                throw new InvalidArgumentException(sprintf('%s must be finite and positive.', $name));
            }
        }
    }

    /**
     * Return the initial inbound stream receive window.
     */
    public function initialReceiveWindow(): int
    {
        return min(65_535, $this->maxPendingBodyBytesPerStream);
    }
}
