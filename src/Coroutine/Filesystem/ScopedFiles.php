<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Coroutine\Filesystem;

use Infocyph\Runwire\Coroutine\CoroutineScope;
use Infocyph\Runwire\Filesystem\AsyncFileSystem;
use Infocyph\Runwire\RequestDeadline;
use LogicException;
use Throwable;

/**
 * Fiber-facing filesystem operations via the same bounded off-loop executor.
 *
 * The facade borrows AsyncFileSystem and automatically passes its scope's
 * cancellation owner through every blocking-worker operation.
 */
final readonly class ScopedFiles
{
    /** Borrow the shared filesystem executor and its active coroutine scope. */
    public function __construct(private AsyncFileSystem $files, private CoroutineScope $scope) {}

    /** Await a bounded binary file read without blocking the owning reactor. */
    public function read(
        string $path,
        int $length,
        int $offset = 0,
        ?RequestDeadline $deadline = null,
    ): string
    {
        $result = $this->await(
            fn(callable $complete): int => $this->files->read(
                $path,
                $length,
                $complete,
                $offset,
                $this->scope->cancellation(),
                $deadline,
            ),
        );
        if (!is_string($result)) {
            throw new LogicException('Scoped file read returned an invalid response.');
        }

        return $result;
    }

    /** Await a rename with explicit filesystem semantics and no atomicity claim. */
    public function rename(string $from, string $to, ?RequestDeadline $deadline = null): bool
    {
        $result = $this->await(
            fn(callable $complete): int => $this->files->rename(
                $from,
                $to,
                $complete,
                $this->scope->cancellation(),
                $deadline,
            ),
        );
        if (!is_bool($result)) {
            throw new LogicException('Scoped file rename returned an invalid result.');
        }

        return $result;
    }

    /**
     * Await bounded metadata stat in the existing scope.
     *
     * @return array{size:int,mtime:int,mode:int}
     */
    public function stat(string $path, ?RequestDeadline $deadline = null): array
    {
        $result = $this->await(
            fn(callable $complete): int => $this->files->stat(
                $path,
                $complete,
                $this->scope->cancellation(),
                $deadline,
            ),
        );
        if (!is_array($result)
            || !is_int($result['size'] ?? null)
            || !is_int($result['mtime'] ?? null)
            || !is_int($result['mode'] ?? null)) {
            throw new LogicException('Scoped file stat returned invalid metadata.');
        }

        return ['size' => $result['size'], 'mtime' => $result['mtime'], 'mode' => $result['mode']];
    }

    /** Await a bounded worker-local unlink. */
    public function unlink(string $path, ?RequestDeadline $deadline = null): bool
    {
        $result = $this->await(
            fn(callable $complete): int => $this->files->unlink(
                $path,
                $complete,
                $this->scope->cancellation(),
                $deadline,
            ),
        );
        if (!is_bool($result)) {
            throw new LogicException('Scoped file unlink returned an invalid result.');
        }

        return $result;
    }

    /** Await a bounded binary file write at the supplied absolute offset. */
    public function write(
        string $path,
        string $bytes,
        int $offset = 0,
        ?RequestDeadline $deadline = null,
    ): int
    {
        $result = $this->await(
            fn(callable $complete): int => $this->files->write(
                $path,
                $bytes,
                $complete,
                $offset,
                $this->scope->cancellation(),
                $deadline,
            ),
        );
        if (!is_int($result)) {
            throw new LogicException('Scoped file write returned an invalid response.');
        }

        return $result;
    }

    /**
     * @param callable(callable(mixed, ?Throwable): void): int $dispatch
     */
    private function await(callable $dispatch): mixed
    {
        $this->scope->cancellation()->throwIfCancelled();
        $deferred = $this->scope->deferred();
        $jobId = $dispatch(static function (mixed $value, ?Throwable $error) use ($deferred): void {
            if ($error !== null) {
                $deferred->reject($error);

                return;
            }

            $deferred->resolve($value);
        });

        try {
            return $deferred->future()->await();
        } finally {
            // No effect after a settled job; it prevents orphaned queued jobs
            // when an unrelated scope failure interrupts a pending await.
            $this->files->cancel($jobId);
        }
    }
}
