<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Network;

use Infocyph\Runwire\Network\Enum\DatagramWriteState;

/**
 * Carries the state and accepted byte count of a datagram write attempt.
 */
final readonly class DatagramWriteResult
{
    /**
     * Create a datagram write result.
     */
    public function __construct(
        public DatagramWriteState $state,
        public int $bytes = 0,
    ) {}

    /**
     * Determine whether the datagram was sent successfully.
     */
    public function sent(): bool
    {
        return $this->state === DatagramWriteState::SENT;
    }
}
