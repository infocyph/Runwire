<?php

declare(strict_types=1);

namespace Infocyph\Runwire\WebSocket;

use InvalidArgumentException;

/**
 * Defines bounded WebSocket frame, message, fairness, heartbeat, and close policies.
 */
final readonly class WebSocketOptions
{
    /**
     * Create and validate native WebSocket limits.
     */
    public function __construct(
        public int $maxFramePayloadBytes = 65_536,
        public int $maxMessageBytes = 1_048_576,
        public int $maxBufferedBytes = 1_048_576,
        public int $maxReadBytesPerTurn = 262_144,
        public int $maxFramesPerTurn = 128,
        public float $heartbeatIntervalSeconds = 30.0,
        public float $idleTimeoutSeconds = 60.0,
        public float $closeTimeoutSeconds = 5.0,
    ) {
        self::validateIntegerLimits(
            $maxFramePayloadBytes,
            $maxMessageBytes,
            $maxBufferedBytes,
            $maxReadBytesPerTurn,
            $maxFramesPerTurn,
        );
        self::validateTimeouts(
            $heartbeatIntervalSeconds,
            $idleTimeoutSeconds,
            $closeTimeoutSeconds,
        );
    }

    private static function validateIntegerLimits(
        int $maxFramePayloadBytes,
        int $maxMessageBytes,
        int $maxBufferedBytes,
        int $maxReadBytesPerTurn,
        int $maxFramesPerTurn,
    ): void {
        foreach ([
            'maxFramePayloadBytes' => $maxFramePayloadBytes,
            'maxMessageBytes' => $maxMessageBytes,
            'maxBufferedBytes' => $maxBufferedBytes,
            'maxReadBytesPerTurn' => $maxReadBytesPerTurn,
            'maxFramesPerTurn' => $maxFramesPerTurn,
        ] as $name => $value) {
            if ($value <= 0) {
                throw new InvalidArgumentException(sprintf('%s must be positive.', $name));
            }
        }
        if ($maxFramePayloadBytes > 1_048_576) {
            throw new InvalidArgumentException('WebSocket frame payload limit cannot exceed 1 MiB.');
        }
        if ($maxMessageBytes < $maxFramePayloadBytes || $maxMessageBytes > 16_777_216) {
            throw new InvalidArgumentException('WebSocket message limit must be between the frame limit and 16 MiB.');
        }
        if ($maxBufferedBytes < $maxFramePayloadBytes + 14 || $maxBufferedBytes > 16_777_216) {
            throw new InvalidArgumentException('WebSocket buffered-byte limit must cover one frame and cannot exceed 16 MiB.');
        }
        if ($maxReadBytesPerTurn > 1_048_576) {
            throw new InvalidArgumentException('WebSocket read work per turn cannot exceed 1 MiB.');
        }
        if ($maxFramesPerTurn > 1_024) {
            throw new InvalidArgumentException('WebSocket frame work per turn cannot exceed 1024 frames.');
        }
    }

    private static function validateTimeouts(
        float $heartbeatIntervalSeconds,
        float $idleTimeoutSeconds,
        float $closeTimeoutSeconds,
    ): void {
        foreach ([
            'heartbeatIntervalSeconds' => $heartbeatIntervalSeconds,
            'idleTimeoutSeconds' => $idleTimeoutSeconds,
            'closeTimeoutSeconds' => $closeTimeoutSeconds,
        ] as $name => $seconds) {
            if (!is_finite($seconds) || $seconds <= 0.0) {
                throw new InvalidArgumentException(sprintf('%s must be finite and positive.', $name));
            }
        }
        if ($heartbeatIntervalSeconds >= $idleTimeoutSeconds) {
            throw new InvalidArgumentException('WebSocket heartbeat interval must be lower than the idle timeout.');
        }
        if ($closeTimeoutSeconds > 30.0) {
            throw new InvalidArgumentException('WebSocket close timeout cannot exceed 30 seconds.');
        }
    }
}
