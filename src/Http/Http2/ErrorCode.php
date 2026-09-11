<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Http2;

enum ErrorCode: int
{
    case CANCEL = 0x8;

    case COMPRESSION_ERROR = 0x9;

    case CONNECT_ERROR = 0xA;

    case ENHANCE_YOUR_CALM = 0xB;

    case FLOW_CONTROL_ERROR = 0x3;

    case FRAME_SIZE_ERROR = 0x6;

    case HTTP_1_1_REQUIRED = 0xD;

    case INADEQUATE_SECURITY = 0xC;

    case INTERNAL_ERROR = 0x2;

    case NO_ERROR = 0x0;

    case PROTOCOL_ERROR = 0x1;

    case REFUSED_STREAM = 0x7;

    case SETTINGS_TIMEOUT = 0x4;

    case STREAM_CLOSED = 0x5;
}
