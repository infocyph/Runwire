<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Http3\Qpack;

use Infocyph\Runwire\Http\Http3\Enum\ErrorCode;
use Infocyph\Runwire\Http\Http3\Http3Exception;

/**
 * Encodes and decodes QPACK prefixed integers.
 */
final class IntegerCodec
{
    /**
     * Decode one complete QPACK prefixed integer and advance the offset.
     */
    public static function decode(string $data, int &$offset, int $prefixBits, ErrorCode $errorCode): int
    {
        $decoded = self::tryDecode($data, $offset, $prefixBits, $errorCode);
        if ($decoded === null) {
            throw new Http3Exception($errorCode, 'Truncated QPACK prefixed integer.');
        }

        [$value, $offset] = $decoded;

        return $value;
    }

    /**
     * Encode an integer using a QPACK prefix width and mask.
     */
    public static function encode(int $value, int $prefixBits, int $prefixMask = 0): string
    {
        self::validatePrefix($prefixBits);
        if ($value < 0) {
            throw new \InvalidArgumentException('QPACK integer cannot be negative.');
        }

        $maxPrefix = (1 << $prefixBits) - 1;
        if ($value < $maxPrefix) {
            return self::byte($prefixMask | $value);
        }

        $encoded = self::byte($prefixMask | $maxPrefix);
        $value -= $maxPrefix;
        while ($value >= 128) {
            $encoded .= self::byte(($value & 0x7F) | 0x80);
            $value >>= 7;
        }

        return $encoded . self::byte($value);
    }

    /** @return array{0: int, 1: int}|null */
    public static function tryDecode(string $data, int $offset, int $prefixBits, ErrorCode $errorCode): ?array
    {
        self::validatePrefix($prefixBits);
        if (!isset($data[$offset])) {
            return null;
        }

        $maxPrefix = (1 << $prefixBits) - 1;
        $value = ord($data[$offset++]) & $maxPrefix;
        if ($value < $maxPrefix) {
            return [$value, $offset];
        }

        $shift = 0;
        while (isset($data[$offset])) {
            $byte = ord($data[$offset++]);
            $part = $byte & 0x7F;
            if ($shift > 56 || $part > ((PHP_INT_MAX - $value) >> $shift)) {
                throw new Http3Exception($errorCode, 'QPACK integer exceeds platform range.');
            }
            $value += $part << $shift;
            if (($byte & 0x80) === 0) {
                return [$value, $offset];
            }
            $shift += 7;
        }

        return null;
    }

    private static function byte(int $value): string
    {
        if ($value < 0 || $value > 255) {
            throw new \LogicException('QPACK byte value is outside the octet range.');
        }

        return chr($value);
    }

    private static function validatePrefix(int $bits): void
    {
        if ($bits < 1 || $bits > 8) {
            throw new \InvalidArgumentException('QPACK integer prefix width must be between 1 and 8 bits.');
        }
    }
}
