<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Network;

use InvalidArgumentException;

final readonly class ConnectionLimits
{
    public function __construct(
        public int $readChunkBytes = 65_536,
        public int $maxReadBytesPerTick = 262_144,
        public int $receiveLowWatermarkBytes = 262_144,
        public int $receiveHighWatermarkBytes = 524_288,
        public int $maxReceiveBufferBytes = 1_048_576,
        public int $sendLowWatermarkBytes = 262_144,
        public int $sendHighWatermarkBytes = 524_288,
        public int $maxSendBufferBytes = 1_048_576,
        public int $maxWriteBytesPerTick = 262_144,
        public ?float $idleTimeoutSeconds = null,
        public ?float $lifetimeTimeoutSeconds = null,
    ) {
        foreach ([
            'readChunkBytes' => $readChunkBytes,
            'maxReadBytesPerTick' => $maxReadBytesPerTick,
            'maxReceiveBufferBytes' => $maxReceiveBufferBytes,
            'maxSendBufferBytes' => $maxSendBufferBytes,
            'maxWriteBytesPerTick' => $maxWriteBytesPerTick,
        ] as $name => $value) {
            if ($value <= 0) {
                throw new InvalidArgumentException(sprintf('%s must be positive.', $name));
            }
        }

        self::validateWatermarks('receive', $receiveLowWatermarkBytes, $receiveHighWatermarkBytes, $maxReceiveBufferBytes);
        self::validateWatermarks('send', $sendLowWatermarkBytes, $sendHighWatermarkBytes, $maxSendBufferBytes);

        foreach (['idleTimeoutSeconds' => $idleTimeoutSeconds, 'lifetimeTimeoutSeconds' => $lifetimeTimeoutSeconds] as $name => $value) {
            if ($value !== null && (!is_finite($value) || $value <= 0)) {
                throw new InvalidArgumentException(sprintf('%s must be null or a finite positive number.', $name));
            }
        }
    }

    private static function validateWatermarks(string $name, int $low, int $high, int $max): void
    {
        if ($low < 0 || $high <= 0 || $low >= $high || $high > $max) {
            throw new InvalidArgumentException(sprintf(
                '%s watermarks must satisfy 0 <= low < high <= max.',
                ucfirst($name),
            ));
        }
    }
}
