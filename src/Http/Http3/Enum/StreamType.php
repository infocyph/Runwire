<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Http3\Enum;

/**
 * Defines HTTP/3 unidirectional stream type identifiers.
 */
enum StreamType: int
{
    case CONTROL = 0x00;

    case PUSH = 0x01;

    case QPACK_DECODER = 0x03;

    case QPACK_ENCODER = 0x02;
}
