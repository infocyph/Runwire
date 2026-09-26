<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Process\Internal;

use Infocyph\Runwire\Exception\ProcessException;

/**
 * Owns a child-process resource and its termination lifecycle.
 */
final class ProcessHandle
{
    private const int MAX_DETACHED_HANDLES = 64;

    /** @var list<array{resource: resource, pid: ?int, process_group: bool}> */
    private static array $detached = [];

    private readonly ?int $pid;

    private readonly bool $processGroup;

    /** @var resource|null */
    private mixed $resource;

    /** @param resource $resource */
    public function __construct(mixed $resource)
    {
        $this->resource = $resource;
        $status = proc_get_status($resource);
        $this->pid = $status['pid'] > 1 ? $status['pid'] : null;
        $this->processGroup = $this->isolateProcessGroup();
    }

    /**
     * Release handles for previously detached children that have since exited.
     */
    public static function reapDetached(): void
    {
        $running = [];
        foreach (self::$detached as $entry) {
            $resource = $entry['resource'];
            if (!is_resource($resource)) {
                continue;
            }
            $status = proc_get_status($resource);
            if ($status['running']) {
                $running[] = $entry;

                continue;
            }

            proc_close($resource);
        }

        self::$detached = $running;
    }

    /**
     * Forcefully terminates the owned process tree if it is still running.
     */
    public function abort(): bool
    {
        if (!is_resource($this->resource)) {
            return false;
        }

        $status = proc_get_status($this->resource);
        if (!$status['running']) {
            return true;
        }

        return ProcessTerminator::force($this->resource, $this->pid, $this->processGroup);
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
                self::retainDetached($resource, $this->pid, $this->processGroup);
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

    /**
     * Gracefully signals the owned process tree when it is still running.
     */
    public function terminateGracefully(): bool
    {
        if (!is_resource($this->resource)) {
            return false;
        }

        $status = proc_get_status($this->resource);
        if (!$status['running']) {
            return true;
        }

        return ProcessTerminator::graceful($this->resource, $this->pid, $this->processGroup);
    }

    /** @param resource $resource */
    private static function retainDetached(mixed $resource, ?int $pid, bool $processGroup): void
    {
        self::reapDetached();
        while (count(self::$detached) >= self::MAX_DETACHED_HANDLES) {
            $oldest = array_shift(self::$detached);
            if (!is_resource($oldest['resource'])) {
                continue;
            }

            $status = proc_get_status($oldest['resource']);
            if ($status['running']) {
                ProcessTerminator::force(
                    $oldest['resource'],
                    $oldest['pid'],
                    $oldest['process_group'],
                );
            }
            proc_close($oldest['resource']);
        }

        self::$detached[] = [
            'resource' => $resource,
            'pid' => $pid,
            'process_group' => $processGroup,
        ];
    }

    private function isolateProcessGroup(): bool
    {
        if (
            DIRECTORY_SEPARATOR === '\\'
            || $this->pid === null
            || !function_exists('posix_setpgid')
        ) {
            return false;
        }

        set_error_handler(static fn(int $severity): bool => $severity === E_WARNING);
        try {
            return posix_setpgid($this->pid, $this->pid);
        } finally {
            restore_error_handler();
        }
    }
}
