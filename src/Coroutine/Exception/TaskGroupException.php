<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Coroutine\Exception;

use RuntimeException;
use Throwable;

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

    /** @return non-empty-list<Throwable> */
    public function failures(): array
    {
        return $this->failures;
    }
}
