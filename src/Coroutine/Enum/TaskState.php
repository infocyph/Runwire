<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Coroutine\Enum;

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
