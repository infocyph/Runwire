<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Http3\Qpack\Enum;

/**
 * Identifies QPACK decoder-stream instruction kinds.
 */
enum DecoderInstructionType
{
    case INSERT_COUNT_INCREMENT;

    case SECTION_ACKNOWLEDGEMENT;

    case STREAM_CANCELLATION;
}
