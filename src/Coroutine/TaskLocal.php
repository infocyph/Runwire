<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Coroutine;

use Infocyph\Runwire\Coroutine\Enum\TaskLocalInheritance;

final readonly class TaskLocal
{
    public function __construct(
        private mixed $default = null,
        private TaskLocalInheritance $inheritance = TaskLocalInheritance::SNAPSHOT,
    ) {}

    public function default(): mixed
    {
        return $this->default;
    }

    public function inheritance(): TaskLocalInheritance
    {
        return $this->inheritance;
    }
}
