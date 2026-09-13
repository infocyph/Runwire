<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Coroutine\Internal;

use Infocyph\Runwire\Coroutine\Exception\CoroutineOverflowException;
use Infocyph\Runwire\Coroutine\Task;
use LogicException;
use SplQueue;
use Throwable;

/** @internal */
final class ReadyQueue
{
    /** @var array<int, true> */
    private array $queued = [];

    /** @var SplQueue<ReadyItem> */
    private readonly SplQueue $queue;

    public function __construct(private readonly int $maxBacklog)
    {
        $this->queue = new SplQueue();
    }

    public function count(): int
    {
        return $this->queue->count();
    }

    public function dequeue(): ReadyItem
    {
        if ($this->queue->isEmpty()) {
            throw new LogicException('Cannot dequeue an empty coroutine ready queue.');
        }

        $item = $this->queue->dequeue();
        unset($this->queued[$item->task->id()]);

        return $item;
    }

    public function enqueue(Task $task, mixed $value = null, ?Throwable $error = null): bool
    {
        $id = $task->id();
        if (isset($this->queued[$id])) {
            return false;
        }
        if ($this->queue->count() >= $this->maxBacklog) {
            throw new CoroutineOverflowException('Coroutine ready backlog limit exceeded.');
        }

        $this->queued[$id] = true;
        $this->queue->enqueue(new ReadyItem($task, $value, $error));

        return true;
    }

    public function isEmpty(): bool
    {
        return $this->queue->isEmpty();
    }
}
