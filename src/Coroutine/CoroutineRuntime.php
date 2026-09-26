<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Coroutine;

use Closure;
use Infocyph\Runwire\CancellationSource;
use Infocyph\Runwire\Coroutine\Internal\FiberScheduler;
use Infocyph\Runwire\Loop\LoopInterface;
use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\RequestContext;
use LogicException;

/**
 * Runs structured coroutine scopes on a single scheduler and event loop.
 */
final class CoroutineRuntime
{
    private readonly FiberScheduler $scheduler;

    private int $attachedRequestScopes = 0;

    private bool $requestRunning = false;

    private bool $running = false;

    /**
     * Creates a coroutine runtime with optional loop and scheduler policy overrides.
     */
    public function __construct(
        ?LoopInterface $loop = null,
        ?CoroutinePolicy $policy = null,
    ) {
        $this->scheduler = new FiberScheduler(
            $loop ?? new SelectLoop(),
            $policy ?? new CoroutinePolicy(),
        );
    }

    /** @internal */
    public function activeTaskCount(): int
    {
        return $this->scheduler->activeTaskCount();
    }

    /**
     * Attach one request scope to the existing scheduler without driving its loop.
     *
     * @param callable(CoroutineScope): mixed $callback
     * @param callable(Task): void|null $completed
     */
    public function attachRequest(RequestContext $context, callable $callback, ?callable $completed = null): Task
    {
        if ($this->running) {
            throw new LogicException('Attached request scopes cannot start while standalone coroutine execution owns the loop.');
        }
        if ($context->completed()) {
            throw new LogicException('Completed request context cannot own coroutine work.');
        }

        $context->cancellation->throwIfCancelled();
        $source = CancellationSource::linked($context->cancellation, $context->deadline());
        $scope = new CoroutineScope($this->scheduler, $source);
        $closure = Closure::fromCallable($callback);
        $completion = $completed === null ? null : Closure::fromCallable($completed);
        $closed = false;
        ++$this->attachedRequestScopes;

        try {
            return $this->scheduler->spawn(
                static fn(): mixed => $scope->execute($closure),
                $source,
                function (Task $task) use ($scope, $completion, &$closed): void {
                    if ($closed || !$task->isComplete()) {
                        return;
                    }

                    $closed = true;
                    $scope->close();
                    --$this->attachedRequestScopes;
                    $completion?->__invoke($task);
                },
            );
        } catch (\Throwable $error) {
            if (!$closed) {
                $closed = true;
                $scope->close();
                --$this->attachedRequestScopes;
            }

            throw $error;
        }
    }

    /**
     * Returns a snapshot of the current coroutine runtime diagnostics.
     */
    public function diagnostics(): CoroutineDiagnosticsSnapshot
    {
        return $this->scheduler->diagnostics(
            rootScopesActive: $this->running ? 1 : 0,
            requestScopesActive: ($this->requestRunning ? 1 : 0) + $this->attachedRequestScopes,
        );
    }

    /** @param callable(CoroutineScope): mixed $callback */
    public function run(callable $callback): mixed
    {
        return $this->execute(new CancellationSource(), $callback);
    }

    /** @param callable(CoroutineScope): mixed $callback */
    public function runRequest(RequestContext $context, callable $callback): mixed
    {
        if ($context->completed()) {
            throw new LogicException('Completed request context cannot own coroutine work.');
        }

        $context->cancellation->throwIfCancelled();
        $this->requestRunning = true;

        try {
            return $this->execute(
                CancellationSource::linked($context->cancellation, $context->deadline()),
                $callback,
            );
        } finally {
            $this->requestRunning = false;
        }
    }

    /** @param callable(CoroutineScope): mixed $callback */
    private function execute(CancellationSource $source, callable $callback): mixed
    {
        if ($this->running || $this->attachedRequestScopes > 0) {
            $source->dispose();

            throw new LogicException(
                $this->running
                    ? 'Nested CoroutineRuntime::run() cannot start a second event loop; use the active scope.'
                    : 'Standalone coroutine execution cannot drive a loop with attached request scopes.',
            );
        }

        $this->running = true;
        $scope = new CoroutineScope($this->scheduler, $source);
        $closure = Closure::fromCallable($callback);

        try {
            $root = $this->scheduler->spawn(
                static fn(): mixed => $scope->execute($closure),
                $source,
            );
            $this->scheduler->drive();

            return $root->result();
        } finally {
            $scope->close();
            $this->running = false;
        }
    }
}
