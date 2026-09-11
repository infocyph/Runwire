<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Http3;

enum SettingIdentifier: int
{
    case QPACK_MAX_TABLE_CAPACITY = 0x01;
    case MAX_FIELD_SECTION_SIZE = 0x06;
    case QPACK_BLOCKED_STREAMS = 0x07;
}
