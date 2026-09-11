<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Http2;

enum StreamState: string
{
    case IDLE = 'idle';
    case RESERVED_LOCAL = 'reserved_local';
    case RESERVED_REMOTE = 'reserved_remote';
    case OPEN = 'open';
    case HALF_CLOSED_LOCAL = 'half_closed_local';
    case HALF_CLOSED_REMOTE = 'half_closed_remote';
    case CLOSED = 'closed';
}
