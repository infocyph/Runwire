<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Network;

enum ConnectionState: string
{
    case CLOSED = 'closed';

    case DRAINING = 'draining';

    case OPEN = 'open';
}
