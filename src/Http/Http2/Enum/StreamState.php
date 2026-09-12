<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Http2\Enum;

enum StreamState: string
{
    case CLOSED = 'closed';

    case HALF_CLOSED_LOCAL = 'half_closed_local';

    case HALF_CLOSED_REMOTE = 'half_closed_remote';

    case IDLE = 'idle';

    case OPEN = 'open';

    case RESERVED_LOCAL = 'reserved_local';

    case RESERVED_REMOTE = 'reserved_remote';
}
