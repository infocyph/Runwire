<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Filesystem;

use Infocyph\Runwire\CancellationToken;
use Infocyph\Runwire\RequestDeadline;
use Throwable;

/**
 * Specification for a worker-owned file session, not a PHP stream resource.
 *
 * An implementation must not expose a blocking descriptor on the reactor.
 */
interface AsyncFileHandleInterface
{
    /** Release the worker session and cancel outstanding operations. */
    public function close(): void;

    /** Return the caller-authorized access mode. */
    public function mode(): FileAccessMode;

    /** @param callable(string, ?Throwable):void $completion */
    public function read(
        int $length,
        callable $completion,
        int $offset = 0,
        ?CancellationToken $token = null,
        ?RequestDeadline $deadline = null,
    ): int;

    /** @param callable(array<string, int>, ?Throwable):void $completion */
    public function stat(
        callable $completion,
        ?CancellationToken $token = null,
        ?RequestDeadline $deadline = null,
    ): int;

    /** @param callable(int, ?Throwable):void $completion */
    public function write(
        string $bytes,
        callable $completion,
        int $offset = 0,
        ?CancellationToken $token = null,
        ?RequestDeadline $deadline = null,
    ): int;
}
