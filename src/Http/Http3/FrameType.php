<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Http3;

enum FrameType: int
{
    case DATA = 0x00;
    case HEADERS = 0x01;
    case CANCEL_PUSH = 0x03;
    case SETTINGS = 0x04;
    case PUSH_PROMISE = 0x05;
    case GOAWAY = 0x07;
    case MAX_PUSH_ID = 0x0D;
}
