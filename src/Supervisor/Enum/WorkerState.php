<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Supervisor\Enum;

enum WorkerState: string
{
    case BUSY = 'busy';

    case DRAINING = 'draining';

    case EXITED = 'exited';

    case FAILED = 'failed';

    case IDLE = 'idle';

    case READY = 'ready';

    case STARTING = 'starting';

    case STOPPING = 'stopping';

    case UNHEALTHY = 'unhealthy';

    public function serving(): bool
    {
        return $this === self::READY || $this === self::IDLE || $this === self::BUSY;
    }
}
