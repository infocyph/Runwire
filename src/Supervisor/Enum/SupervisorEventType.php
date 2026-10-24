<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Supervisor\Enum;

/**
 * Identifies lifecycle and health events emitted by the supervisor.
 */
enum SupervisorEventType: string
{
    case GENERATION_READY = 'generation_ready';

    case RELOAD_COMPLETED = 'reload_completed';

    case RELOAD_FAILED = 'reload_failed';

    case RELOAD_STARTED = 'reload_started';

    case REQUEST_DEADLINE_EXCEEDED = 'request_deadline_exceeded';

    case SUPERVISOR_STARTING = 'supervisor_starting';

    case SUPERVISOR_STOPPED = 'supervisor_stopped';

    case SUPERVISOR_STOPPING = 'supervisor_stopping';

    case WORKER_DRAIN_COMPLETED = 'worker_drain_completed';

    case WORKER_DRAIN_STARTED = 'worker_drain_started';

    case WORKER_EXITED = 'worker_exited';

    case WORKER_READY = 'worker_ready';

    case WORKER_RECYCLE_COMPLETED = 'worker_recycle_completed';

    case WORKER_RECYCLE_STARTED = 'worker_recycle_started';

    case WORKER_RESTART_SCHEDULED = 'worker_restart_scheduled';

    case WORKER_SPAWNED = 'worker_spawned';

    case WORKER_STOP_REQUESTED = 'worker_stop_requested';

    case WORKER_UNHEALTHY = 'worker_unhealthy';
}
