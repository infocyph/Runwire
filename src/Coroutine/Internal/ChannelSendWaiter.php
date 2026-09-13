<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Coroutine\Internal;

use Infocyph\Runwire\CancellationToken;
use Infocyph\Runwire\Coroutine\Deferred;

/** @internal */
final readonly class ChannelSendWaiter
{
    public function __construct(
        public Deferred $deferred,
        public CancellationToken $cancellation,
        public mixed $value,
    ) {}
}
