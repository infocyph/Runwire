<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Http3;

final class FrameWriter
{
    public static function encode(Frame $frame): string
    {
        return VarIntCodec::encode($frame->type)
            . VarIntCodec::encode(strlen($frame->payload))
            . $frame->payload;
    }
}
