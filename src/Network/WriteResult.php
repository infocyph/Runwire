<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Network;

final readonly class WriteResult
{
    public function __construct(
        public WriteState $state,
        public int $bufferedBytes,
    ) {}

    public function accepted(): bool
    {
        return $this->state === WriteState::ACCEPTED || $this->state === WriteState::PRESSURED;
    }

    public function pressured(): bool
    {
        return $this->state === WriteState::PRESSURED;
    }
}
