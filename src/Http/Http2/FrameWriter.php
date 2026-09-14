<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Http2;

use Infocyph\Runwire\Http\Http2\Enum\ErrorCode;
use Infocyph\Runwire\Http\Http2\Enum\FrameType;

/**
 * Encodes HTTP/2 frames and common control-frame payloads.
 */
final class FrameWriter
{
    /**
     * Encode a frame to its wire representation.
     */
    public static function encode(Frame $frame): string
    {
        $length = strlen($frame->payload);

        return self::byte(($length >> 16) & 0xFF)
            . self::byte(($length >> 8) & 0xFF)
            . self::byte($length & 0xFF)
            . self::byte($frame->type)
            . self::byte($frame->flags)
            . pack('N', $frame->streamId & 0x7FFF_FFFF)
            . $frame->payload;
    }

    /**
     * Build a GOAWAY frame.
     */
    public static function goAway(int $lastStreamId, ErrorCode $error, string $debug = ''): Frame
    {
        return new Frame(
            FrameType::GOAWAY->value,
            0,
            0,
            pack('NN', $lastStreamId & 0x7FFF_FFFF, $error->value) . $debug,
        );
    }

    /**
     * Build an RST_STREAM frame.
     */
    public static function rstStream(int $streamId, ErrorCode $error): Frame
    {
        if ($streamId === 0) {
            throw new \InvalidArgumentException('RST_STREAM requires a non-zero stream ID.');
        }

        return new Frame(FrameType::RST_STREAM->value, 0, $streamId, pack('N', $error->value));
    }

    /** @param array<int, int> $settings */
    public static function settings(array $settings = [], bool $ack = false): Frame
    {
        if ($ack) {
            return new Frame(FrameType::SETTINGS->value, 0x1, 0, '');
        }
        $payload = '';
        foreach ($settings as $identifier => $value) {
            $payload .= pack('nN', $identifier, $value);
        }

        return new Frame(FrameType::SETTINGS->value, 0, 0, $payload);
    }

    /**
     * Build a WINDOW_UPDATE frame.
     */
    public static function windowUpdate(int $streamId, int $increment): Frame
    {
        if ($increment <= 0 || $increment > 0x7FFF_FFFF) {
            throw new \InvalidArgumentException('HTTP/2 WINDOW_UPDATE increment must be between 1 and 2147483647.');
        }

        return new Frame(FrameType::WINDOW_UPDATE->value, 0, $streamId, pack('N', $increment));
    }

    private static function byte(int $value): string
    {
        if ($value < 0 || $value > 0xFF) {
            throw new \InvalidArgumentException('HTTP/2 frame byte must be between 0 and 255.');
        }

        return chr($value);
    }
}
