<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Supervisor;

enum WorkerState: string
{
    case STARTING = 'starting';
    case READY = 'ready';
    case DRAINING = 'draining';
    case STOPPING = 'stopping';
    case FAILED = 'failed';
}
