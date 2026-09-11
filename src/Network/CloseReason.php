<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Network;

enum CloseReason: string
{
    case LOCAL_GRACEFUL = 'local_graceful';
    case LOCAL_ABORT = 'local_abort';
    case PEER_CLOSED = 'peer_closed';
    case READ_ERROR = 'read_error';
    case WRITE_ERROR = 'write_error';
    case IDLE_TIMEOUT = 'idle_timeout';
    case LIFETIME_TIMEOUT = 'lifetime_timeout';
}
