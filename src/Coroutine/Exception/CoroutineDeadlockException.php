<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Coroutine\Exception;

use RuntimeException;

/**
 * Signals that the coroutine scheduler is idle while live tasks remain.
 */
final class CoroutineDeadlockException extends RuntimeException
{
    /**
     * Creates an exception describing the remaining live task count.
     */
    public function __construct(public readonly int $liveTasks)
    {
        parent::__construct(sprintf(
            'Coroutine scheduler became idle with %d live task(s).',
            $liveTasks,
        ));
    }
}
