<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Coroutine\Exception;

use RuntimeException;
use Throwable;

/**
 * Reports one or more unhandled failures from a coroutine task group.
 */
final class TaskGroupException extends RuntimeException
{
    /** @param non-empty-list<Throwable> $failures */
    public function __construct(private readonly array $failures)
    {
        parent::__construct(
            sprintf('Coroutine task group failed with %d unhandled task failure(s).', count($failures)),
            0,
            $failures[0],
        );
    }

    /**
     * Return the failures captured by the task group.
     *
     * @return non-empty-list<Throwable>
     */
    public function failures(): array
    {
        return $this->failures;
    }
}
