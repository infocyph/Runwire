<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Coroutine\Exception;

/**
 * Signals that a barrier generation can no longer complete successfully.
 */
final class BarrierBrokenException extends SynchronizationException
{
    /**
     * Creates an exception for the broken barrier generation.
     */
    public function __construct(public readonly int $generation)
    {
        parent::__construct(sprintf('Coroutine barrier generation %d was broken.', $generation));
    }
}
