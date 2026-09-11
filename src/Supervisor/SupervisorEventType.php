<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Supervisor;

enum SupervisorEventType: string
{
    case RELOAD_COMPLETED = 'reload_completed';

    case RELOAD_STARTED = 'reload_started';

    case SUPERVISOR_STARTING = 'supervisor_starting';

    case SUPERVISOR_STOPPED = 'supervisor_stopped';

    case SUPERVISOR_STOPPING = 'supervisor_stopping';

    case WORKER_EXITED = 'worker_exited';

    case WORKER_READY = 'worker_ready';

    case WORKER_RECYCLE_STARTED = 'worker_recycle_started';

    case WORKER_RESTART_SCHEDULED = 'worker_restart_scheduled';

    case WORKER_SPAWNED = 'worker_spawned';

    case WORKER_STOP_REQUESTED = 'worker_stop_requested';
}
