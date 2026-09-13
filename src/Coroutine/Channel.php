<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Coroutine;

use Infocyph\Runwire\Coroutine\Exception\ChannelClosedException;
use Infocyph\Runwire\Coroutine\Exception\CoroutineOverflowException;
use Infocyph\Runwire\Coroutine\Internal\ChannelSendWaiter;
use Infocyph\Runwire\Coroutine\Internal\FiberScheduler;
use Infocyph\Runwire\Coroutine\Internal\PrimitiveWaiter;
use InvalidArgumentException;
use SplQueue;

final class Channel
{
    /** @var SplQueue<mixed> */
    private readonly SplQueue $buffer;

    private bool $closed = false;

    private int $nextWaiterId = 1;

    /** @var array<int, PrimitiveWaiter> */
    private array $receivers = [];

    /** @var array<int, ChannelSendWaiter> */
    private array $senders = [];

    /** @internal */
    public function __construct(
        private readonly FiberScheduler $scheduler,
        private readonly int $capacity,
        private readonly int $maxWaiters,
    ) {
        if ($capacity < 0) {
            throw new InvalidArgumentException('Coroutine channel capacity must be non-negative.');
        }

        $this->buffer = new SplQueue();
    }

    public function capacity(): int
    {
        return $this->capacity;
    }

    public function close(): bool
    {
        if ($this->closed) {
            return false;
        }

        $this->closed = true;
        $this->drainBufferedReceivers();
        $this->rejectSenders();
        $this->rejectReceivers();

        return true;
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    public function receive(): mixed
    {
        $cancellation = $this->scheduler->currentCancellation();
        $cancellation->throwIfCancelled();

        if (!$this->buffer->isEmpty()) {
            $value = $this->buffer->dequeue();
            $this->promoteSender();

            return $value;
        }

        $sender = $this->takeSender();
        if ($sender !== null) {
            $sender->deferred->resolve(null);

            return $sender->value;
        }
        if ($this->closed) {
            throw new ChannelClosedException('Cannot receive from a closed and drained coroutine channel.');
        }

        $this->assertWaiterCapacity();
        $id = $this->nextWaiterId++;
        $waiter = new PrimitiveWaiter($this->scheduler->deferred(), $cancellation);
        $this->receivers[$id] = $waiter;

        try {
            return $waiter->deferred->future()->awaitCommitted();
        } finally {
            unset($this->receivers[$id]);
        }
    }

    public function send(mixed $value): void
    {
        $cancellation = $this->scheduler->currentCancellation();
        $cancellation->throwIfCancelled();
        if ($this->closed) {
            throw new ChannelClosedException('Cannot send to a closed coroutine channel.');
        }

        $receiver = $this->takeReceiver();
        if ($receiver !== null) {
            $receiver->deferred->resolve($value);

            return;
        }
        if ($this->capacity > $this->buffer->count()) {
            $this->buffer->enqueue($value);

            return;
        }

        $this->assertWaiterCapacity();
        $id = $this->nextWaiterId++;
        $waiter = new ChannelSendWaiter($this->scheduler->deferred(), $cancellation, $value);
        $this->senders[$id] = $waiter;

        try {
            $waiter->deferred->future()->awaitCommitted();
        } finally {
            unset($this->senders[$id]);
        }
    }

    public function size(): int
    {
        return $this->buffer->count();
    }

    private function assertWaiterCapacity(): void
    {
        if (count($this->senders) + count($this->receivers) >= $this->maxWaiters) {
            throw new CoroutineOverflowException('Coroutine channel waiter limit exceeded.');
        }
    }

    private function drainBufferedReceivers(): void
    {
        while (!$this->buffer->isEmpty()) {
            $receiver = $this->takeReceiver();
            if ($receiver === null) {
                return;
            }

            $receiver->deferred->resolve($this->buffer->dequeue());
        }
    }

    private function promoteSender(): void
    {
        if ($this->closed || $this->capacity === 0) {
            return;
        }

        $sender = $this->takeSender();
        if ($sender === null) {
            return;
        }

        $this->buffer->enqueue($sender->value);
        $sender->deferred->resolve(null);
    }

    private function rejectReceivers(): void
    {
        $waiters = $this->receivers;
        $this->receivers = [];

        foreach ($waiters as $waiter) {
            if (!$waiter->cancellation->isCancelled()) {
                $waiter->deferred->reject(
                    new ChannelClosedException('Coroutine channel closed before a value was received.'),
                );
            }
        }
    }

    private function rejectSenders(): void
    {
        $waiters = $this->senders;
        $this->senders = [];

        foreach ($waiters as $waiter) {
            if (!$waiter->cancellation->isCancelled()) {
                $waiter->deferred->reject(new ChannelClosedException('Coroutine channel closed before send completed.'));
            }
        }
    }

    private function takeReceiver(): ?PrimitiveWaiter
    {
        while ($this->receivers !== []) {
            $id = array_key_first($this->receivers);
            $waiter = $this->receivers[$id];
            unset($this->receivers[$id]);
            if (!$waiter->cancellation->isCancelled()) {
                return $waiter;
            }
        }

        return null;
    }

    private function takeSender(): ?ChannelSendWaiter
    {
        while ($this->senders !== []) {
            $id = array_key_first($this->senders);
            $waiter = $this->senders[$id];
            unset($this->senders[$id]);
            if (!$waiter->cancellation->isCancelled()) {
                return $waiter;
            }
        }

        return null;
    }
}
