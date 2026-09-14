<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Network\Enum;

/**
 * Classifies connection termination reasons.
 */
enum CloseReason: string
{
    case IDLE_TIMEOUT = 'idle_timeout';

    case LIFETIME_TIMEOUT = 'lifetime_timeout';

    case LOCAL_ABORT = 'local_abort';

    case LOCAL_GRACEFUL = 'local_graceful';

    case PEER_CLOSED = 'peer_closed';

    case PROTOCOL_ERROR = 'protocol_error';

    case READ_ERROR = 'read_error';

    case WRITE_ERROR = 'write_error';
}
