<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Http3\Qpack;

enum DecoderInstructionType
{
    case SECTION_ACKNOWLEDGEMENT;
    case STREAM_CANCELLATION;
    case INSERT_COUNT_INCREMENT;
}
