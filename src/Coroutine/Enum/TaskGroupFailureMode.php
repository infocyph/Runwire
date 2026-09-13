<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Coroutine\Enum;

enum TaskGroupFailureMode: string
{
    case COLLECT_ALL = 'collect_all';

    case FAIL_FAST = 'fail_fast';
}
