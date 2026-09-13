<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Coroutine;

use Infocyph\Runwire\CancellationSource;
use Infocyph\Runwire\CancellationToken;
use Infocyph\Runwire\Coroutine\Internal\FiberScheduler;
use Infocyph\Runwire\Runtime\Enum\CancellationReason;
use LogicException;
use Throwable;

final class CoroutineScope
{
    /** @var array<int, Task> */
    private array $children = [];

    private bool $closed = false;

    /** @internal */
    public function __construct(
        private readonly FiberScheduler $scheduler,
        private readonly CancellationSource $source,
    ) {}

    /** @internal */
    public function cancelChildren(CancellationReason $reason): void
    {
        foreach ($this->children as $task) {
            if (!$task->isComplete()) {
                $task->cancel($reason);
            }
        }
    }

    public function cancellation(): CancellationToken
    {
        return $this->source->token();
    }

    /** @internal */
    public function close(): void
    {
        $this->closed = true;
        $this->children = [];
        $this->source->dispose();
    }

    public function deferred(): Deferred
    {
        $this->assertOpen();

        return $this->scheduler->deferred();
    }

    /** @internal */
    public function join(bool $propagateFailure = true): void
    {
        $firstError = null;

        foreach ($this->children as $task) {
            if ($task->observed()) {
                continue;
            }

            try {
                $task->await();
            } catch (Throwable $error) {
                $firstError ??= $error;
            }
        }
        $this->children = [];

        if ($propagateFailure && $firstError !== null) {
            throw $firstError;
        }
    }

    public function sleep(float $seconds): void
    {
        $this->assertOpen();
        $this->scheduler->sleep($seconds);
    }

    /** @param callable(): mixed $callback */
    public function spawn(callable $callback): Task
    {
        $this->assertOpen();
        $task = $this->scheduler->spawn($callback, $this->source->child());
        $this->children[$task->id()] = $task;

        return $task;
    }

    /** @param resource $stream */
    public function waitReadable(mixed $stream): void
    {
        $this->assertOpen();
        $this->scheduler->suspendReadable($stream);
    }

    /** @param resource $stream */
    public function waitWritable(mixed $stream): void
    {
        $this->assertOpen();
        $this->scheduler->suspendWritable($stream);
    }

    public function yieldNow(): void
    {
        $this->assertOpen();
        $this->scheduler->yieldNow();
    }

    private function assertOpen(): void
    {
        if ($this->closed) {
            throw new LogicException('Coroutine scope is already closed.');
        }
    }
}
