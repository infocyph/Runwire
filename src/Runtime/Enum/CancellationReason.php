<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Runtime\Enum;

enum CancellationReason: string
{
    case DEADLINE_EXCEEDED = 'deadline_exceeded';

    case HOST_CANCELLED = 'host_cancelled';

    case TRANSPORT_CANCELLED = 'transport_cancelled';

    case WORKER_SHUTDOWN = 'worker_shutdown';
}
