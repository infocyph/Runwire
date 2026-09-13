<?php

declare(strict_types=1);

namespace Infocyph\Runwire;

use Infocyph\Runwire\Cancellation\Internal\CancellationState;

final class CancellationSubscription
{
    /** @internal */
    public function __construct(
        private readonly CancellationState $state,
        private ?int $id,
    ) {}

    public function active(): bool
    {
        return $this->id !== null && $this->state->hasSubscription($this->id);
    }

    public function unsubscribe(): bool
    {
        if ($this->id === null) {
            return false;
        }

        $id = $this->id;
        $this->id = null;

        return $this->state->unsubscribe($id);
    }
}
