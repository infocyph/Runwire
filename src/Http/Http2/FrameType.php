<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Http2;

enum FrameType: int
{
    case DATA = 0x0;
    case HEADERS = 0x1;
    case PRIORITY = 0x2;
    case RST_STREAM = 0x3;
    case SETTINGS = 0x4;
    case PUSH_PROMISE = 0x5;
    case PING = 0x6;
    case GOAWAY = 0x7;
    case WINDOW_UPDATE = 0x8;
    case CONTINUATION = 0x9;
}
