<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Coroutine\Exception;

use RuntimeException;

final class CoroutineDeadlockException extends RuntimeException
{
    public function __construct(public readonly int $liveTasks)
    {
        parent::__construct(sprintf(
            'Coroutine scheduler became idle with %d live task(s).',
            $liveTasks,
        ));
    }
}
