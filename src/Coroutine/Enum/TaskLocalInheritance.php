<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Coroutine\Enum;

/**
 * Defines how task-local values are inherited by child tasks.
 */
enum TaskLocalInheritance: string
{
    case NONE = 'none';

    case SNAPSHOT = 'snapshot';
}
