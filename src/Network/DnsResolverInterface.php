<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Network;

use Infocyph\Runwire\CancellationToken;
use Infocyph\Runwire\RequestDeadline;
use Throwable;

/** A cancellable off-loop DNS lookup used by Happy Eyeballs. */
interface DnsResolverInterface
{
    /** Cancel one DNS job or coalesced caller by its handle. */
    public function cancel(int $id): bool;

    /** @param callable(list<string>,?Throwable):void $completion */
    public function resolve(
        string $hostname,
        callable $completion,
        ?CancellationToken $token = null,
        ?RequestDeadline $deadline = null,
    ): int;
}
