<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Process\Internal;

use Infocyph\Runwire\Exception\ProcessException;

final class ProcessHandle
{
    /** @var resource|null */
    private mixed $resource;

    /** @param resource $resource */
    public function __construct(mixed $resource)
    {
        $this->resource = $resource;
    }

    public function abort(): void
    {
        if (!is_resource($this->resource)) {
            return;
        }

        $status = @proc_get_status($this->resource);
        if ($status['running']) {
            @proc_terminate($this->resource, SIGKILL);
        }
    }

    public function close(): ?int
    {
        if (!is_resource($this->resource)) {
            return null;
        }

        $resource = $this->resource;
        $this->resource = null;

        return @proc_close($resource);
    }

    /** @return resource */
    public function resource(): mixed
    {
        if (!is_resource($this->resource)) {
            throw new ProcessException('Child process handle is unavailable.');
        }

        return $this->resource;
    }
}
