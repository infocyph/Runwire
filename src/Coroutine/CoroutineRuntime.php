<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Coroutine;

use Closure;
use Infocyph\Runwire\CancellationSource;
use Infocyph\Runwire\Coroutine\Internal\FiberScheduler;
use Infocyph\Runwire\Loop\LoopInterface;
use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Runtime\Enum\CancellationReason;
use LogicException;
use Throwable;

final class CoroutineRuntime
{
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
            static function () use ($closure, $scope): mixed {
                try {
                    $result = $closure($scope);
                    $scope->join();

                    return $result;
                } catch (Throwable $error) {
                    $scope->cancelChildren(CancellationReason::HOST_CANCELLED);
                    $scope->join(false);

                    throw $error;
                }
            },
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
