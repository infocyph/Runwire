<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Http3;

use Infocyph\Runwire\Http\Http3\Enum\ErrorCode;

/**
 * Encodes and decodes QUIC variable-length integers used by HTTP/3.
 */
final class VarIntCodec
{
    public const int MAX_VALUE = 4_611_686_018_427_387_903;

    /**
     * Decode one complete QUIC variable-length integer and advance the offset.
     */
    public static function decode(string $bytes, int &$offset = 0): int
    {
        $decoded = self::tryDecode($bytes, $offset);
        if ($decoded === null) {
            throw new Http3Exception(ErrorCode::FRAME_ERROR, 'Truncated QUIC variable-length integer.');
        }

        [$value, $offset] = $decoded;

        return $value;
    }

    /**
     * Encode a non-negative integer using QUIC variable-length representation.
     */
    public static function encode(int $value): string
    {
        if ($value < 0 || $value > self::MAX_VALUE) {
            throw new \InvalidArgumentException('QUIC variable-length integer is outside the 62-bit range.');
        }
        if ($value <= 63) {
            return chr($value);
        }
        if ($value <= 16_383) {
            return pack('n', 0x4000 | $value);
        }
        if ($value <= 1_073_741_823) {
            return pack('N', 0x8000_0000 | $value);
        }

        $high = intdiv($value, 4_294_967_296);
        $low = $value % 4_294_967_296;

        return pack('NN', 0xC000_0000 | $high, $low);
    }

    /** @return array{0: int, 1: int}|null */
    public static function tryDecode(string $bytes, int $offset = 0): ?array
    {
        if (!isset($bytes[$offset])) {
            return null;
        }

        $first = ord($bytes[$offset]);
        $length = 1 << ($first >> 6);
        if (strlen($bytes) - $offset < $length) {
            return null;
        }

        return [self::decodeWidth($bytes, $offset, $length), $offset + $length];
    }

    private static function decodeWidth(string $bytes, int $offset, int $length): int
    {
        $value = ord($bytes[$offset]) & 0x3F;

        for ($position = 1; $position < $length; ++$position) {
            $value = ($value << 8) | ord($bytes[$offset + $position]);
        }

        return $value;
    }
}
