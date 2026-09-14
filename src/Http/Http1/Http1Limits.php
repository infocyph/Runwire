<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Http1;

use InvalidArgumentException;

/**
 * Defines parser, buffering, response, and timeout limits for HTTP/1.1.
 */
final readonly class Http1Limits
{
    /**
     * Create and validate HTTP/1.1 connection limits.
     */
    public function __construct(
        public int $maxRequestLineBytes = 8_192,
        public int $maxHeaderLineBytes = 8_192,
        public int $maxHeaderBytes = 65_536,
        public int $maxHeaderCount = 100,
        public int $maxBodyBytes = 16_777_216,
        public int $bodyLowWatermarkBytes = 262_144,
        public int $bodyHighWatermarkBytes = 524_288,
        public int $maxPendingBodyBytes = 1_048_576,
        public int $maxChunkLineBytes = 128,
        public int $maxKeepAliveRequests = 1_000,
        public int $maxParserStepsPerTick = 256,
        public int $maxResponseHeaderBytes = 65_536,
        public int $maxResponseHeaderCount = 100,
        public int $maxResponseChunkBytes = 65_536,
        public float $headerTimeoutSeconds = 10.0,
        public float $bodyIdleTimeoutSeconds = 30.0,
    ) {
        foreach ([
            'maxRequestLineBytes' => $maxRequestLineBytes,
            'maxHeaderLineBytes' => $maxHeaderLineBytes,
            'maxHeaderBytes' => $maxHeaderBytes,
            'maxHeaderCount' => $maxHeaderCount,
            'maxBodyBytes' => $maxBodyBytes,
            'maxPendingBodyBytes' => $maxPendingBodyBytes,
            'maxChunkLineBytes' => $maxChunkLineBytes,
            'maxKeepAliveRequests' => $maxKeepAliveRequests,
            'maxParserStepsPerTick' => $maxParserStepsPerTick,
            'maxResponseHeaderBytes' => $maxResponseHeaderBytes,
            'maxResponseHeaderCount' => $maxResponseHeaderCount,
            'maxResponseChunkBytes' => $maxResponseChunkBytes,
        ] as $name => $value) {
            if ($value <= 0) {
                throw new InvalidArgumentException(sprintf('%s must be positive.', $name));
            }
        }
        if ($bodyLowWatermarkBytes < 0
            || $bodyLowWatermarkBytes >= $bodyHighWatermarkBytes
            || $bodyHighWatermarkBytes > $maxPendingBodyBytes) {
            throw new InvalidArgumentException('Body watermarks must satisfy 0 <= low < high <= max pending.');
        }
        foreach (['headerTimeoutSeconds' => $headerTimeoutSeconds, 'bodyIdleTimeoutSeconds' => $bodyIdleTimeoutSeconds] as $name => $value) {
            if (!is_finite($value) || $value <= 0) {
                throw new InvalidArgumentException(sprintf('%s must be finite and positive.', $name));
            }
        }
    }
}
