<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Network;

use Infocyph\Runwire\Network\Enum\WriteState;

/**
 * Describes the outcome and buffered-byte state of a connection write.
 */
final readonly class WriteResult
{
    /**
     * Creates a write result for the supplied state and buffer depth.
     */
    public function __construct(
        public WriteState $state,
        public int $bufferedBytes,
    ) {}

    /**
     * Reports whether the write was accepted by the connection.
     */
    public function accepted(): bool
    {
        return $this->state === WriteState::ACCEPTED || $this->state === WriteState::PRESSURED;
    }

    /**
     * Reports whether the accepted write placed the connection under pressure.
     */
    public function pressured(): bool
    {
        return $this->state === WriteState::PRESSURED;
    }
}
