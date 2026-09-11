<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Network;

enum ConnectionState: string
{
    case OPEN = 'open';
    case DRAINING = 'draining';
    case CLOSED = 'closed';
}
