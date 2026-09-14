<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Network\Internal;

use LogicException;

/**
 * Tracks exclusive ownership of a connection's callback slots.
 *
 * @internal
 */
final class ConnectionCallbackOwnership
{
    private ?object $owner = null;

    /**
     * Ensures no adapter currently owns the connection callback slots.
     */
    public function assertUnclaimed(): void
    {
        if ($this->owner !== null) {
            throw new LogicException('Connection callback slots are exclusively owned by an adapter.');
        }
    }

    /**
     * Grants exclusive callback-slot ownership to an adapter.
     */
    public function claim(object $owner, bool $slotsConfigured, bool $closed): void
    {
        if ($this->owner !== null) {
            throw new LogicException('Connection callback slots are already exclusively owned.');
        }
        if ($slotsConfigured) {
            throw new LogicException('Connection callbacks are already configured and cannot be claimed exclusively.');
        }
        if ($closed) {
            throw new LogicException('Closed connections cannot grant exclusive callback ownership.');
        }

        $this->owner = $owner;
    }

    /**
     * Releases callback-slot ownership held by the supplied adapter.
     */
    public function release(object $owner): void
    {
        if ($this->owner !== $owner) {
            throw new LogicException('Only the exclusive callback owner may release connection callback slots.');
        }

        $this->owner = null;
    }
}
