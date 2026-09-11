<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Http3\Qpack;

use Infocyph\Runwire\Http\Http2\Hpack\HpackException;
use Infocyph\Runwire\Http\Http2\Hpack\HuffmanCodec;
use Infocyph\Runwire\Http\Http3\ErrorCode;
use Infocyph\Runwire\Http\Http3\Http3Exception;

final class StringCodec
{
    public static function decode(
        string $data,
        int &$offset,
        int $prefixBits,
        int $huffmanMask,
        int $maxOutputBytes,
        ErrorCode $errorCode,
    ): string {
        $decoded = self::tryDecode($data, $offset, $prefixBits, $huffmanMask, $maxOutputBytes, $errorCode);
        if ($decoded === null) {
            throw new Http3Exception($errorCode, 'Truncated QPACK string literal.');
        }

        [$value, $offset] = $decoded;

        return $value;
    }

    public static function encode(
        string $value,
        int $prefixBits,
        int $prefixMask,
        int $huffmanMask,
        bool $allowHuffman = true,
    ): string {
        $encoded = $allowHuffman ? (new HuffmanCodec())->encode($value) : $value;
        $useHuffman = $allowHuffman && strlen($encoded) < strlen($value);
        $payload = $useHuffman ? $encoded : $value;

        return IntegerCodec::encode(
            strlen($payload),
            $prefixBits,
            $prefixMask | ($useHuffman ? $huffmanMask : 0),
        ) . $payload;
    }

    /** @return array{0: string, 1: int}|null */
    public static function tryDecode(
        string $data,
        int $offset,
        int $prefixBits,
        int $huffmanMask,
        int $maxOutputBytes,
        ErrorCode $errorCode,
    ): ?array {
        if (!isset($data[$offset])) {
            return null;
        }
        $huffman = (ord($data[$offset]) & $huffmanMask) !== 0;
        $length = IntegerCodec::tryDecode($data, $offset, $prefixBits, $errorCode);
        if ($length === null) {
            return null;
        }
        [$encodedLength, $payloadOffset] = $length;
        if (strlen($data) - $payloadOffset < $encodedLength) {
            return null;
        }

        $encoded = substr($data, $payloadOffset, $encodedLength);
        if (!$huffman) {
            if ($encodedLength > $maxOutputBytes) {
                throw new Http3Exception($errorCode, 'QPACK string exceeds configured decoded limit.');
            }

            return [$encoded, $payloadOffset + $encodedLength];
        }

        try {
            $decoded = (new HuffmanCodec())->decode($encoded, $maxOutputBytes);
        } catch (HpackException $exception) {
            throw new Http3Exception($errorCode, 'Invalid QPACK Huffman string.', $exception);
        }

        return [$decoded, $payloadOffset + $encodedLength];
    }
}
