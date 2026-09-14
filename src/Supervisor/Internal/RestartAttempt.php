<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Supervisor\Internal;

/**
 * Describes one scheduled worker restart attempt and its backoff delay.
 */
final readonly class RestartAttempt
{
    /**
     * Create a restart attempt value object.
     */
    public function __construct(
        public int $count,
        public float $delaySeconds,
    ) {}
}
