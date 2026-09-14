<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Http3;

/**
 * Encodes HTTP/3 frames to their wire representation.
 */
final class FrameWriter
{
    /**
     * Encode an HTTP/3 frame including its type and payload length.
     */
    public static function encode(Frame $frame): string
    {
        return VarIntCodec::encode($frame->type)
            . VarIntCodec::encode(strlen($frame->payload))
            . $frame->payload;
    }
}
