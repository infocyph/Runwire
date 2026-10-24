<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Coroutine\Internal;

use Infocyph\Runwire\CancellationToken;
use Infocyph\Runwire\Coroutine\Deferred;

/**
 * Carries a pending channel send operation and its cancellation context.
 *
 * @internal
 */
final readonly class ChannelSendWaiter
{
    /**
     * Create a pending channel send waiter.
     */
    public function __construct(
        public Deferred $deferred,
        public CancellationToken $cancellation,
        public mixed $value,
    ) {}
}
