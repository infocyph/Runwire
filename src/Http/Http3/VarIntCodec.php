<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Http3;

final class VarIntCodec
{
    public const int MAX_VALUE = 4_611_686_018_427_387_903;

    public static function decode(string $bytes, int &$offset = 0): int
    {
        $decoded = self::tryDecode($bytes, $offset);
        if ($decoded === null) {
            throw new Http3Exception(ErrorCode::FRAME_ERROR, 'Truncated QUIC variable-length integer.');
        }

        [$value, $offset] = $decoded;

        return $value;
    }

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
        if ($length === 1) {
            return ord($bytes[$offset]) & 0x3F;
        }

        if ($length === 2) {
            $decoded = unpack('nvalue', substr($bytes, $offset, 2));

            return ((int) $decoded['value']) & 0x3FFF;
        }

        if ($length === 4) {
            $decoded = unpack('Nvalue', substr($bytes, $offset, 4));

            return ((int) $decoded['value']) & 0x3FFF_FFFF;
        }

        $decoded = unpack('Nhigh/Nlow', substr($bytes, $offset, 8));
        $high = ((int) $decoded['high']) & 0x3FFF_FFFF;

        return ($high * 4_294_967_296) + (int) $decoded['low'];
    }
}
