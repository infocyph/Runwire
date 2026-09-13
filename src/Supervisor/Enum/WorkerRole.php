<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Supervisor\Enum;

enum WorkerRole: string
{
    case CUSTOM = 'custom';

    case HTTP = 'http';

    case SERVICE = 'service';

    case TASK = 'task';

    public function background(): bool
    {
        return $this === self::TASK || $this === self::SERVICE;
    }

    public function defaultReloadable(): bool
    {
        return $this !== self::SERVICE;
    }
}
