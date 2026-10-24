<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Http2\Hpack;

/**
 * Encodes and decodes HPACK variable-length integers.
 */
final class IntegerCodec
{
    /**
     * Decode an HPACK integer and advance the byte offset.
     */
    public static function decode(string $data, int &$offset, int $prefixBits): int
    {
        self::validatePrefix($prefixBits);
        if (!isset($data[$offset])) {
            throw new HpackException('Truncated HPACK integer.');
        }

        $maxPrefix = (1 << $prefixBits) - 1;
        $value = ord($data[$offset++]) & $maxPrefix;
        if ($value < $maxPrefix) {
            return $value;
        }

        $shift = 0;
        while (true) {
            if (!isset($data[$offset])) {
                throw new HpackException('Truncated HPACK integer continuation.');
            }
            $byte = ord($data[$offset++]);
            $part = $byte & 0x7F;
            if ($shift > 56 || $part > ((PHP_INT_MAX - $value) >> $shift)) {
                throw new HpackException('HPACK integer exceeds platform range.');
            }
            $value += $part << $shift;
            if (($byte & 0x80) === 0) {
                return $value;
            }
            $shift += 7;
        }
    }

    /**
     * Encode an HPACK integer with the supplied prefix width and mask.
     */
    public static function encode(int $value, int $prefixBits, int $prefixMask = 0): string
    {
        self::validatePrefix($prefixBits);
        if ($value < 0) {
            throw new \InvalidArgumentException('HPACK integer cannot be negative.');
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

    private static function byte(int $value): string
    {
        if ($value < 0 || $value > 0xFF) {
            throw new \InvalidArgumentException('HPACK encoded byte must be between 0 and 255.');
        }

        return chr($value);
    }

    private static function validatePrefix(int $bits): void
    {
        if ($bits < 1 || $bits > 8) {
            throw new \InvalidArgumentException('HPACK integer prefix width must be between 1 and 8 bits.');
        }
    }
}
