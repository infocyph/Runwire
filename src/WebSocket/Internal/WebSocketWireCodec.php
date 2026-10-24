<?php

declare(strict_types=1);

namespace Infocyph\Runwire\WebSocket\Internal;

use InvalidArgumentException;

/**
 * Encodes server frames and validates/decodes close payloads.
 *
 * @internal
 */
final readonly class WebSocketWireCodec
{
    /**
     * Validate a close code allowed on the wire.
     */
    public static function assertCloseCode(int $code): void
    {
        $standard = $code >= 1000
            && $code <= 1014
            && !in_array($code, [1004, 1005, 1006], true);
        $application = $code >= 3000 && $code <= 4999;
        if (!$standard && !$application) {
            throw new InvalidArgumentException('WebSocket close code is not valid for transmission.');
        }
    }

    /**
     * Validate UTF-8 required by text and close-reason fields.
     */
    public static function assertUtf8(string $value, string $label): void
    {
        if ($value !== '' && preg_match('//u', $value) !== 1) {
            throw new InvalidArgumentException(sprintf('%s must contain valid UTF-8.', $label));
        }
    }

    /**
     * Encode one unmasked server-to-client frame.
     */
    public static function frame(int $opcode, string $payload): string
    {
        if ($opcode < 0 || $opcode > 15) {
            throw new InvalidArgumentException('WebSocket opcode must be between 0 and 15.');
        }

        $length = strlen($payload);
        $first = chr(0x80 | $opcode);
        if ($length < 126) {
            return $first . chr($length) . $payload;
        }
        if ($length <= 65_535) {
            return $first . chr(126) . pack('n', $length) . $payload;
        }

        return $first . chr(127) . pack('NN', 0, $length) . $payload;
    }

    /** Encode a client-to-server frame with a fresh cryptographic masking key. */
    public static function clientFrame(int $opcode, string $payload, bool $fin = true): string
    {
        if (!in_array($opcode, [0x0, 0x1, 0x2, 0x8, 0x9, 0xA], true)) {
            throw new InvalidArgumentException('WebSocket client opcode is reserved or unsupported.');
        }

        $length = strlen($payload);
        if ($length > 1_048_576 || ($opcode >= 0x8 && (!$fin || $length > 125))) {
            throw new InvalidArgumentException('WebSocket client control or payload limits exceeded.');
        }

        $first = chr(($fin ? 0x80 : 0) | $opcode);
        if ($length < 126) {
            $header = $first . chr(0x80 | $length);
        } elseif ($length <= 65_535) {
            $header = $first . chr(0x80 | 126) . pack('n', $length);
        } else {
            $header = $first . chr(0x80 | 127) . pack('NN', 0, $length);
        }

        $mask = random_bytes(4);

        return $header . $mask . ($payload ^ str_repeat($mask, intdiv($length + 3, 4)));
    }

    /**
     * Decode and validate a peer close payload.
     *
     * @return array{0: int, 1: string}
     */
    public static function parseClosePayload(string $payload): array
    {
        $length = strlen($payload);
        if ($length === 0) {
            return [1005, ''];
        }
        if ($length === 1) {
            throw new WebSocketProtocolException(1002, 'WebSocket close payload cannot contain one byte.');
        }

        $code = (ord($payload[0]) << 8) | ord($payload[1]);

        try {
            self::assertCloseCode($code);
        } catch (InvalidArgumentException) {

            throw new WebSocketProtocolException(1002, 'Peer sent an invalid WebSocket close code.');
        }

        $reason = substr($payload, 2);
        if ($reason !== '' && preg_match('//u', $reason) !== 1) {
            throw new WebSocketProtocolException(1007, 'Peer sent an invalid UTF-8 WebSocket close reason.');
        }

        return [$code, $reason];
    }
}
