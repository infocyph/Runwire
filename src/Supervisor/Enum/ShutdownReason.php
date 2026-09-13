<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Supervisor\Enum;

enum ShutdownReason: string
{
    case DEPLOYMENT_RELOAD = 'deployment_reload';

    case FATAL_RUNTIME_ERROR = 'fatal_runtime_error';

    case MANUAL_RECYCLE = 'manual_recycle';

    case RECYCLE_LIFETIME = 'recycle_lifetime';

    case RECYCLE_MEMORY_LIMIT = 'recycle_memory_limit';

    case RECYCLE_REQUEST_LIMIT = 'recycle_request_limit';

    case SUPERVISOR_STOP = 'supervisor_stop';
}
