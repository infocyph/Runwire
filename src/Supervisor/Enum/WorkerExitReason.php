<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Supervisor\Enum;

enum WorkerExitReason: string
{
    case APPLICATION_FATAL = 'application_fatal';
    case CRASH = 'crash';
    case NORMAL_SHUTDOWN = 'normal_shutdown';
    case PLANNED_RECYCLE = 'planned_recycle';
    case PLANNED_RELOAD = 'planned_reload';
    case READINESS_TIMEOUT = 'readiness_timeout';
    case RESTART_BUDGET_EXHAUSTED = 'restart_budget_exhausted';
    case SIGNAL_EXIT = 'signal_exit';
    case STARTUP_FAILURE = 'startup_failure';
}
