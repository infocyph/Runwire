<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Coroutine;

use Closure;
use Infocyph\Runwire\CancellationSource;
use Infocyph\Runwire\Coroutine\Internal\FiberScheduler;
use Infocyph\Runwire\Loop\LoopInterface;
use Infocyph\Runwire\Loop\SelectLoop;
use LogicException;

final class CoroutineRuntime
{
    private readonly FiberScheduler $scheduler;

    private bool $running = false;

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

    /** @param callable(CoroutineScope): mixed $callback */
    public function run(callable $callback): mixed
    {
        if ($this->running) {
            throw new LogicException('Nested CoroutineRuntime::run() cannot start a second event loop; use the active scope.');
        }

        $this->running = true;
        $source = new CancellationSource();
        $scope = new CoroutineScope($this->scheduler, $source);
        $closure = Closure::fromCallable($callback);
        $root = $this->scheduler->spawn(
            static fn(): mixed => $scope->execute($closure),
            $source,
        );

        try {
            $this->scheduler->drive();

            return $root->result();
        } finally {
            $scope->close();
            $this->running = false;
        }
    }
}
