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

final class CoroutineRuntime
{
    private bool $requestRunning = false;

    private bool $running = false;

    private readonly FiberScheduler $scheduler;

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

    public function diagnostics(): CoroutineDiagnosticsSnapshot
    {
        return $this->scheduler->diagnostics(
            rootScopesActive: $this->running ? 1 : 0,
            requestScopesActive: $this->requestRunning ? 1 : 0,
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
        if ($this->running) {
            $source->dispose();

            throw new LogicException('Nested CoroutineRuntime::run() cannot start a second event loop; use the active scope.');
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
