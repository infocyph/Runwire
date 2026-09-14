<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Network\Enum;

/**
 * Describes acceptance, pressure, closure, or limit rejection for stream writes.
 */
enum WriteState: string
{
    case ACCEPTED = 'accepted';

    case CLOSED = 'closed';

    case PRESSURED = 'pressured';

    case REJECTED_LIMIT = 'rejected_limit';
}
