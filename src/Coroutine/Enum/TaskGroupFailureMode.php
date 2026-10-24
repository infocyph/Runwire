<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Coroutine\Enum;

/**
 * Defines how a coroutine task group propagates child failures.
 */
enum TaskGroupFailureMode: string
{
    case COLLECT_ALL = 'collect_all';

    case FAIL_FAST = 'fail_fast';
}
