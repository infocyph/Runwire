<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Runtime\Enum;

/**
 * Classifies request and coroutine cancellation causes.
 */
enum CancellationReason: string
{
    case DEADLINE_EXCEEDED = 'deadline_exceeded';

    case HOST_CANCELLED = 'host_cancelled';

    case SCOPE_FAILED = 'scope_failed';

    case TRANSPORT_CANCELLED = 'transport_cancelled';

    case WORKER_SHUTDOWN = 'worker_shutdown';
}
