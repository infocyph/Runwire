<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Filesystem;

/** Explicit access capabilities for an asynchronous worker-owned file session. */
enum FileAccessMode: string
{
    case READ = 'read';

    case READ_WRITE = 'read_write';

    case WRITE = 'write';

    public function canRead(): bool
    {
        return $this === self::READ || $this === self::READ_WRITE;
    }

    public function canWrite(): bool
    {
        return $this === self::WRITE || $this === self::READ_WRITE;
    }
}
