<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Http3;

enum FrameType: int
{
    case CANCEL_PUSH = 0x03;

    case DATA = 0x00;

    case GOAWAY = 0x07;

    case HEADERS = 0x01;

    case MAX_PUSH_ID = 0x0D;

    case PUSH_PROMISE = 0x05;

    case SETTINGS = 0x04;
}
