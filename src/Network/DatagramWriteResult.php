<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Network;

use Infocyph\Runwire\Network\Enum\DatagramWriteState;

final readonly class DatagramWriteResult
{
    public function __construct(
        public DatagramWriteState $state,
        public int $bytes = 0,
    ) {}

    public function sent(): bool
    {
        return $this->state === DatagramWriteState::SENT;
    }
}
