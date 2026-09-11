<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Supervisor;

enum SupervisorEventType: string
{
    case SUPERVISOR_STARTING = 'supervisor_starting';
    case SUPERVISOR_STOPPING = 'supervisor_stopping';
    case SUPERVISOR_STOPPED = 'supervisor_stopped';
    case RELOAD_STARTED = 'reload_started';
    case RELOAD_COMPLETED = 'reload_completed';
    case WORKER_SPAWNED = 'worker_spawned';
    case WORKER_READY = 'worker_ready';
    case WORKER_STOP_REQUESTED = 'worker_stop_requested';
    case WORKER_EXITED = 'worker_exited';
    case WORKER_RESTART_SCHEDULED = 'worker_restart_scheduled';
    case WORKER_RECYCLE_STARTED = 'worker_recycle_started';
}
