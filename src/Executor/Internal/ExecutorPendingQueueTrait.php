<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Executor\Internal;

/** @internal Keeps cancellation tombstones in the bounded executor FIFO under control. */
trait ExecutorPendingQueueTrait
{
    /**
     * Canceled queued jobs can leave stale IDs in the FIFO while its one
     * worker is busy. Compact only at a bounded threshold, preserving
     * submission order of active jobs without an unbounded tombstone list.
     */
    private function compactPendingQueue(): void
    {
        $capacity = $this->maxWorkers + $this->maxQueue;
        if ($this->pending->count() <= $capacity * 2 + 32) {
            return;
        }

        $active = [];
        while (!$this->pending->isEmpty()) {
            $id = $this->pending->dequeue();
            if (isset($this->jobs[$id]) && $this->jobs[$id]['worker'] === null) {
                $active[] = $id;
            }
        }
        foreach ($active as $id) {
            $this->pending->enqueue($id);
        }
    }
}
