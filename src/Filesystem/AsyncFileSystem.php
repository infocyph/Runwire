<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Filesystem;

use Infocyph\Runwire\CancellationToken;
use Infocyph\Runwire\Executor\ProcessExecutor;
use Infocyph\Runwire\RequestDeadline;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Explicit bounded filesystem operations on the shared off-loop PHP executor.
 *
 * Each operation owns its short-lived worker-local file handle. This facade
 * borrows the executor and never runs filesystem syscalls on the loop thread.
 * Native acceleration, sandbox traversal and crash-atomic writes are not
 * advertised by this preliminary backend.
 */
final readonly class AsyncFileSystem
{
    public const int MAX_OPERATION_BYTES = 1_048_576;

    /** Borrow a loop-bound ProcessExecutor with caller-selected quotas. */
    public function __construct(private ProcessExecutor $executor) {}

    /** Cancel one queued/active filesystem operation without duplicate callbacks. */
    public function cancel(int $id): bool
    {
        return $this->executor->cancel($id);
    }

    /**
     * Read at most 1 MiB from a file offset without blocking the owner loop.
     *
     * @param callable(string, ?Throwable):void $completion
     */
    public function read(
        string $path,
        int $length,
        callable $completion,
        int $offset = 0,
        ?CancellationToken $token = null,
        ?RequestDeadline $deadline = null,
    ): int {
        self::validatePath($path);
        if ($length < 0 || $length > self::MAX_OPERATION_BYTES || $offset < 0) {
            throw new InvalidArgumentException('Invalid async file read range.');
        }

        return $this->executor->submit(
            'read',
            ['path' => $path, 'length' => $length, 'offset' => $offset],
            static function (mixed $value, ?Throwable $error) use ($completion): void {
                if ($error !== null || !is_string($value)) {
                    $completion('', $error ?? new RuntimeException('File read worker returned invalid bytes.'));

                    return;
                }

                $completion($value, null);
            },
            $token,
            $deadline,
        );
    }

    /**
     * Rename a file using host filesystem semantics; no cross-device promise.
     *
     * @param callable(bool, ?Throwable):void $completion
     */
    public function rename(
        string $from,
        string $to,
        callable $completion,
        ?CancellationToken $token = null,
        ?RequestDeadline $deadline = null,
    ): int {
        self::validatePath($from);
        self::validatePath($to);

        return $this->executor->submit(
            'rename',
            ['from' => $from, 'to' => $to],
            static function (mixed $value, ?Throwable $error) use ($completion): void {
                $completion(
                    $error === null && $value === true,
                    $error ?? ($value === true ? null : new RuntimeException('File rename worker returned an invalid result.')),
                );
            },
            $token,
            $deadline,
        );
    }

    /**
     * Retrieve file metadata with the same cancellation/deadline contract.
     *
     * @param callable(array<string,int>, ?Throwable):void $completion
     */
    public function stat(
        string $path,
        callable $completion,
        ?CancellationToken $token = null,
        ?RequestDeadline $deadline = null,
    ): int {
        self::validatePath($path);

        return $this->executor->submit(
            'stat',
            ['path' => $path],
            static function (mixed $value, ?Throwable $error) use ($completion): void {
                if ($error !== null || !is_array($value)
                    || !is_int($value['size'] ?? null)
                    || !is_int($value['mtime'] ?? null)
                    || !is_int($value['mode'] ?? null)) {
                    $completion([], $error ?? new RuntimeException('File stat worker returned invalid metadata.'));

                    return;
                }

                $completion(['size' => $value['size'], 'mtime' => $value['mtime'], 'mode' => $value['mode']], null);
            },
            $token,
            $deadline,
        );
    }

    /**
     * Remove a file through the worker without following its contents.
     *
     * @param callable(bool, ?Throwable):void $completion
     */
    public function unlink(
        string $path,
        callable $completion,
        ?CancellationToken $token = null,
        ?RequestDeadline $deadline = null,
    ): int {
        self::validatePath($path);

        return $this->executor->submit(
            'unlink',
            ['path' => $path],
            static function (mixed $value, ?Throwable $error) use ($completion): void {
                $completion(
                    $error === null && $value === true,
                    $error ?? ($value === true ? null : new RuntimeException('File unlink worker returned an invalid result.')),
                );
            },
            $token,
            $deadline,
        );
    }

    /**
     * Write a bounded binary chunk at an absolute offset; no implicit truncate.
     *
     * @param callable(int, ?Throwable):void $completion
     */
    public function write(
        string $path,
        string $bytes,
        callable $completion,
        int $offset = 0,
        ?CancellationToken $token = null,
        ?RequestDeadline $deadline = null,
    ): int {
        self::validatePath($path);
        if (strlen($bytes) > self::MAX_OPERATION_BYTES || $offset < 0) {
            throw new InvalidArgumentException('Invalid async file write range.');
        }

        return $this->executor->submit(
            'write',
            ['path' => $path, 'data' => base64_encode($bytes), 'offset' => $offset],
            static function (mixed $value, ?Throwable $error) use ($completion): void {
                if ($error !== null || !is_int($value)) {
                    $completion(0, $error ?? new RuntimeException('File write worker returned invalid byte count.'));

                    return;
                }

                $completion($value, null);
            },
            $token,
            $deadline,
        );
    }

    private static function validatePath(string $path): void
    {
        if ($path === '' || str_contains($path, "\0") || strlen($path) > 4096) {
            throw new InvalidArgumentException('Filesystem path is empty, excessive or contains NUL.');
        }
    }
}
