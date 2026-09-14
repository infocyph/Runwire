<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Coroutine\Enum;

/**
 * Represents the lifecycle state of a coroutine task.
 */
enum TaskState: string
{
    case CANCELLED = 'cancelled';

    case COMPLETED = 'completed';

    case FAILED = 'failed';

    case NEW = 'new';

    case RUNNABLE = 'runnable';

    case RUNNING = 'running';

    case SUSPENDED = 'suspended';
}
