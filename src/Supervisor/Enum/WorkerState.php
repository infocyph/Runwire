<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Supervisor\Enum;

enum WorkerState: string
{
    case DRAINING = 'draining';

    case FAILED = 'failed';

    case READY = 'ready';

    case STARTING = 'starting';

    case STOPPING = 'stopping';
}
