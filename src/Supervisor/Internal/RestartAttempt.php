<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Supervisor\Internal;

final readonly class RestartAttempt
{
    public function __construct(
        public int $count,
        public float $delaySeconds,
    ) {}
}
