<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Supervisor\Enum;

/**
 * Identifies the operational role assigned to a supervised worker group.
 */
enum WorkerRole: string
{
    case CUSTOM = 'custom';

    case HTTP = 'http';

    case SERVICE = 'service';

    case TASK = 'task';

    /**
     * Determine whether the role runs as background work without a request listener.
     */
    public function background(): bool
    {
        return $this === self::TASK || $this === self::SERVICE;
    }

    /**
     * Determine whether workers of this role reload by default.
     */
    public function defaultReloadable(): bool
    {
        return $this !== self::SERVICE;
    }
}
