<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Network\Enum;

/**
 * Represents the lifecycle state of a stream connection.
 */
enum ConnectionState: string
{
    case CLOSED = 'closed';

    case DRAINING = 'draining';

    case OPEN = 'open';
}
