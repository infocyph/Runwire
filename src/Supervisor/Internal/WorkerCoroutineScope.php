<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Supervisor\Internal;

use Closure;
use Infocyph\Runwire\CancellationSource;
use Infocyph\Runwire\Coroutine\CoroutineDiagnosticsSnapshot;
use Infocyph\Runwire\Coroutine\CoroutinePolicy;
use Infocyph\Runwire\Coroutine\CoroutineScope;
use Infocyph\Runwire\Coroutine\Internal\FiberScheduler;
use Infocyph\Runwire\Coroutine\Task;
use Infocyph\Runwire\Exception\CancelledException;
use Infocyph\Runwire\Loop\LoopInterface;
use Infocyph\Runwire\Runtime\Enum\CancellationReason;
use InvalidArgumentException;
use LogicException;
use Throwable;

final class WorkerCoroutineScope
{
    /** @var Closure(): void */
    private readonly Closure $onFailure;

    private readonly FiberScheduler $scheduler;

    private readonly CoroutineScope $scope;

    private readonly CancellationSource $source;

    private int $activeTasks = 0;

    private bool $closed = false;

    private bool $drainExpired = false;

    private bool $draining = false;

    private ?int $graceTimer = null;

    /** @param callable(): void $onFailure */
    public function __construct(
        private readonly LoopInterface $loop,
        private readonly float $shutdownGraceSeconds,
        callable $onFailure,
        ?CoroutinePolicy $policy = null,
    ) {
        if (!is_finite($shutdownGraceSeconds) || $shutdownGraceSeconds <= 0.0) {
            throw new InvalidArgumentException('Worker coroutine shutdown grace must be finite and positive.');
        }

        $failure = Closure::fromCallable($onFailure);
        $this->onFailure = static function () use ($failure): void {
            $failure();
        };
        $this->scheduler = new FiberScheduler($loop, $policy ?? new CoroutinePolicy());
        $this->source = new CancellationSource();
        $this->scope = new CoroutineScope($this->scheduler, $this->source);
    }

    public function activeTaskCount(): int
    {
        return $this->activeTasks;
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;
        $this->draining = true;
        $this->cancelGraceTimer();
        $this->source->cancel(CancellationReason::WORKER_SHUTDOWN);
        $this->scope->close();
    }

    public function diagnostics(): CoroutineDiagnosticsSnapshot
    {
        return $this->scheduler->diagnostics(
            backgroundScopesActive: $this->closed ? 0 : 1,
            backgroundTasksActive: $this->activeTasks,
        );
    }

    public function drain(): void
    {
        if ($this->closed || $this->draining) {
            return;
        }

        $this->draining = true;
        $this->source->cancel(CancellationReason::WORKER_SHUTDOWN);
        if ($this->activeTasks === 0) {
            $this->loop->stop();

            return;
        }

        $this->graceTimer = $this->loop->delay(
            $this->shutdownGraceSeconds,
            function (int $timer): void {
                if ($this->graceTimer !== $timer) {
                    return;
                }

                $this->graceTimer = null;
                $this->drainExpired = $this->activeTasks > 0;
                $this->loop->stop();
            },
        );
    }

    public function drainExpired(): bool
    {
        return $this->drainExpired;
    }

    public function ownsLoop(LoopInterface $loop): bool
    {
        return $this->loop === $loop;
    }

    /** @param callable(CoroutineScope): mixed $callback */
    public function spawn(callable $callback): Task
    {
        if ($this->closed) {
            throw new LogicException('Worker coroutine scope is already closed.');
        }
        if ($this->draining) {
            throw new LogicException('Worker is draining and cannot spawn background coroutine work.');
        }

        $closure = Closure::fromCallable($callback);
        ++$this->activeTasks;

        try {
            return $this->scope->spawn(function () use ($closure): mixed {
                try {
                    return $closure($this->scope);
                } catch (CancelledException $error) {
                    throw $error;
                } catch (Throwable $error) {
                    ($this->onFailure)();

                    throw $error;
                } finally {
                    $this->taskFinished();
                }
            });
        } catch (Throwable $error) {
            --$this->activeTasks;

            throw $error;
        }
    }

    private function cancelGraceTimer(): void
    {
        if ($this->graceTimer === null) {
            return;
        }

        $this->loop->cancel($this->graceTimer);
        $this->graceTimer = null;
    }

    private function taskFinished(): void
    {
        if ($this->activeTasks > 0) {
            --$this->activeTasks;
        }
        if (!$this->draining || $this->activeTasks !== 0) {
            return;
        }

        $this->cancelGraceTimer();
        $this->loop->stop();
    }
}
