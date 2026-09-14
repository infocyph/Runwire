<?php

declare(strict_types=1);

namespace Infocyph\Runwire;

use Infocyph\Runwire\Cancellation\Internal\CancellationState;

/** Represents a removable observer registration on a cancellation state. */
final class CancellationSubscription
{
    /**
     * Creates a subscription wrapper for an internal cancellation observer handle.
     *
     * @internal
     */
    public function __construct(
        private readonly CancellationState $state,
        private ?int $id,
    ) {}

    /** Releases an abandoned observer registration defensively. */
    public function __destruct()
    {
        $this->unsubscribe();
    }

    /** Reports whether the underlying cancellation observer is still registered. */
    public function active(): bool
    {
        return $this->id !== null && $this->state->hasSubscription($this->id);
    }

    /** Removes the observer when it is still active. */
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
