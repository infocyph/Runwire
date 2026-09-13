<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Runtime\Enum;

enum CancellationReason: string
{
    case TRANSPORT_CANCELLED = 'transport_cancelled';
    case DEADLINE_EXCEEDED = 'deadline_exceeded';
    case WORKER_SHUTDOWN = 'worker_shutdown';
    case HOST_CANCELLED = 'host_cancelled';
}
