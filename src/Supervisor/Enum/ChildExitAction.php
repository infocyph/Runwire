<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Supervisor\Enum;

enum ChildExitAction
{
    case CHECK_RELOAD;

    case NONE;

    case RESTART;

    case SPAWN_RECYCLE;

    case STOP_LOOP;
}
