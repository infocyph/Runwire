<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Supervisor\Enum;

/**
 * Describes the supervisor action selected after a child process exits.
 */
enum ChildExitAction
{
    case CHECK_RELOAD;

    case NONE;

    case RESTART;

    case SPAWN_RECYCLE;

    case STOP_LOOP;
}
