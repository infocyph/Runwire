<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Coroutine\Internal;

use Infocyph\Runwire\Coroutine\Task;
use Throwable;

/** @internal */
final readonly class ReadyItem
{
    /**
     * Create a queued task-resume item.
     */
    public function __construct(
        public Task $task,
        public mixed $value = null,
        public ?Throwable $error = null,
        public bool $ignoreCancellation = false,
    ) {}
}
