<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Protocol;

use Infocyph\Runwire\Network\Internal\ByteQueue;
use Infocyph\Runwire\Protocol\Enum\LengthPrefixFormat;
use InvalidArgumentException;

/**
 * Encodes and incrementally decodes frames using a fixed-width length prefix.
 */
final class LengthPrefixedCodec implements FrameCodecInterface
{
    private readonly ByteQueue $buffer;

    private ?int $pendingLength = null;

    /**
     * Creates a length-prefixed codec with the selected prefix format and frame limit.
     */
    public function __construct(
        private readonly LengthPrefixFormat $format = LengthPrefixFormat::UINT32_BE,
        private readonly int $maxFrameBytes = 16_777_216,
    ) {
        if ($maxFrameBytes < 0 || $maxFrameBytes > $format->maximum()) {
            throw new InvalidArgumentException('Maximum frame size is outside the selected length-prefix range.');
        }
        $this->buffer = new ByteQueue();
    }

    /**
     * Returns bytes currently buffered awaiting a complete frame.
     */
    public function bufferedBytes(): int
    {
        return $this->buffer->bytes();
    }

    /**
     * Encodes one frame with its configured length prefix.
     */
    public function encode(string $frame): string
    {
        $length = strlen($frame);
        if ($length > $this->maxFrameBytes || $length > $this->format->maximum()) {
            throw new CodecException('Length-prefixed frame exceeds the configured limit.');
        }

        return $this->encodeLength($length) . $frame;
    }

    /**
     * Decodes up to the requested number of complete frames from incoming bytes.
     */
    public function push(string $bytes, int $maxFrames = 256): array
    {
        if ($maxFrames <= 0) {
            throw new InvalidArgumentException('Maximum frames per decode must be positive.');
        }
        if ($bytes !== '') {
            $this->buffer->append($bytes);
        }

        $frames = [];
        while (count($frames) < $maxFrames) {
            if ($this->pendingLength === null) {
                if ($this->buffer->bytes() < $this->format->value) {
                    break;
                }
                $this->pendingLength = $this->decodeLength($this->buffer->read($this->format->value));
                if ($this->pendingLength > $this->maxFrameBytes) {
                    throw new CodecException('Length-prefixed frame exceeds the configured limit.');
                }
            }

            if ($this->buffer->bytes() < $this->pendingLength) {
                break;
            }

            $frames[] = $this->buffer->read($this->pendingLength);
            $this->pendingLength = null;
        }

        return $frames;
    }

    /**
     * Clears buffered bytes and any pending frame length.
     */
    public function reset(): void
    {
        $this->buffer->clear();
        $this->pendingLength = null;
    }

    private static function decodeUint16(string $prefix): int
    {
        /** @var array{length: int}|false $decoded */
        $decoded = unpack('nlength', $prefix);
        if ($decoded === false) {
            throw new CodecException('Unable to decode UINT16 length prefix.');
        }

        return $decoded['length'];
    }

    private static function decodeUint32(string $prefix): int
    {
        /** @var array{length: int}|false $decoded */
        $decoded = unpack('Nlength', $prefix);
        if ($decoded === false) {
            throw new CodecException('Unable to decode UINT32 length prefix.');
        }

        return $decoded['length'];
    }

    private static function encodeUint8(int $length): string
    {
        if ($length < 0 || $length > 0xFF) {
            throw new CodecException('UINT8 length prefix must be between 0 and 255.');
        }

        return chr($length);
    }

    private function decodeLength(string $prefix): int
    {
        return match ($this->format) {
            LengthPrefixFormat::UINT8 => ord($prefix[0]),
            LengthPrefixFormat::UINT16_BE => self::decodeUint16($prefix),
            LengthPrefixFormat::UINT32_BE => self::decodeUint32($prefix),
        };
    }

    private function encodeLength(int $length): string
    {
        return match ($this->format) {
            LengthPrefixFormat::UINT8 => self::encodeUint8($length),
            LengthPrefixFormat::UINT16_BE => pack('n', $length),
            LengthPrefixFormat::UINT32_BE => pack('N', $length),
        };
    }
}
