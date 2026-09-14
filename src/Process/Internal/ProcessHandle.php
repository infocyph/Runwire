<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Process\Internal;

use Infocyph\Runwire\Exception\ProcessException;

/**
 * Owns a child-process resource and its termination lifecycle.
 */
final class ProcessHandle
{
    /** @var list<resource> */
    private static array $detached = [];

    /** @var resource|null */
    private mixed $resource;

    /** @param resource $resource */
    public function __construct(mixed $resource)
    {
        $this->resource = $resource;
    }

    /**
     * Release handles for previously detached children that have since exited.
     */
    public static function reapDetached(): void
    {
        $running = [];
        foreach (self::$detached as $resource) {
            if (!is_resource($resource)) {
                continue;
            }
            $status = proc_get_status($resource);
            if ($status['running']) {
                $running[] = $resource;

                continue;
            }

            proc_close($resource);
        }

        self::$detached = $running;
    }

    /**
     * Forcefully terminates the process if it is still running.
     */
    public function abort(): void
    {
        if (!is_resource($this->resource)) {
            return;
        }

        $status = proc_get_status($this->resource);
        if ($status['running']) {
            ProcessTerminator::force($this->resource);
        }
    }

    /**
     * Closes the process handle and returns its exit code when available.
     */
    public function close(bool $wait = true): ?int
    {
        if (!is_resource($this->resource)) {
            return null;
        }

        $resource = $this->resource;
        if (!$wait) {
            $status = proc_get_status($resource);
            if ($status['running']) {
                self::$detached[] = $resource;
                $this->resource = null;

                return null;
            }
        }
        $this->resource = null;

        return proc_close($resource);
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
