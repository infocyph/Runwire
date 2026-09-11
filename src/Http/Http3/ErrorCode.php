<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Http3;

enum ErrorCode: int
{
    case NO_ERROR = 0x0100;
    case GENERAL_PROTOCOL_ERROR = 0x0101;
    case INTERNAL_ERROR = 0x0102;
    case STREAM_CREATION_ERROR = 0x0103;
    case CLOSED_CRITICAL_STREAM = 0x0104;
    case FRAME_UNEXPECTED = 0x0105;
    case FRAME_ERROR = 0x0106;
    case EXCESSIVE_LOAD = 0x0107;
    case ID_ERROR = 0x0108;
    case SETTINGS_ERROR = 0x0109;
    case MISSING_SETTINGS = 0x010A;
    case REQUEST_REJECTED = 0x010B;
    case REQUEST_CANCELLED = 0x010C;
    case REQUEST_INCOMPLETE = 0x010D;
    case MESSAGE_ERROR = 0x010E;
    case CONNECT_ERROR = 0x010F;
    case VERSION_FALLBACK = 0x0110;
    case QPACK_DECOMPRESSION_FAILED = 0x0200;
    case QPACK_ENCODER_STREAM_ERROR = 0x0201;
    case QPACK_DECODER_STREAM_ERROR = 0x0202;
}
