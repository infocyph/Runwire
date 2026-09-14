<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Coroutine;

use Infocyph\Runwire\Coroutine\Enum\TaskLocalInheritance;

/**
 * Defines a task-local key with a default value and inheritance policy.
 */
final readonly class TaskLocal
{
    /**
     * Create a task-local key.
     */
    public function __construct(
        private mixed $default = null,
        private TaskLocalInheritance $inheritance = TaskLocalInheritance::SNAPSHOT,
    ) {}

    /**
     * Return the key's default value.
     */
    public function default(): mixed
    {
        return $this->default;
    }

    /**
     * Return the inheritance policy used for child tasks.
     */
    public function inheritance(): TaskLocalInheritance
    {
        return $this->inheritance;
    }
}
