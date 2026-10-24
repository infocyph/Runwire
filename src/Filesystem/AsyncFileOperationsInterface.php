<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Filesystem;

use Infocyph\Runwire\CancellationToken;
use Infocyph\Runwire\RequestDeadline;
use Throwable;

/**
 * Portable asynchronous filesystem API contract.
 *
 * No open-handle implementation is implied: the current AsyncFileSystem
 * facade uses independent worker-local operations only.
 */
interface AsyncFileOperationsInterface
{
    /** Cancel one pending operation, settling its completion at most once. */
    public function cancel(int $id): bool;

    /** @param callable(?AsyncFileHandleInterface, ?Throwable):void $completion */
    public function open(
        string $path,
        FileAccessMode $mode,
        callable $completion,
        ?CancellationToken $token = null,
        ?RequestDeadline $deadline = null,
    ): int;

    /** @param callable(string, ?Throwable):void $completion */
    public function read(
        string $path,
        int $length,
        callable $completion,
        int $offset = 0,
        ?CancellationToken $token = null,
        ?RequestDeadline $deadline = null,
    ): int;

    /** @param callable(bool, ?Throwable):void $completion */
    public function rename(
        string $from,
        string $to,
        callable $completion,
        ?CancellationToken $token = null,
        ?RequestDeadline $deadline = null,
    ): int;

    /** @param callable(array<string, int>, ?Throwable):void $completion */
    public function stat(
        string $path,
        callable $completion,
        ?CancellationToken $token = null,
        ?RequestDeadline $deadline = null,
    ): int;

    /** @param callable(bool, ?Throwable):void $completion */
    public function unlink(
        string $path,
        callable $completion,
        ?CancellationToken $token = null,
        ?RequestDeadline $deadline = null,
    ): int;

    /** @param callable(int, ?Throwable):void $completion */
    public function write(
        string $path,
        string $bytes,
        callable $completion,
        int $offset = 0,
        ?CancellationToken $token = null,
        ?RequestDeadline $deadline = null,
    ): int;
}
