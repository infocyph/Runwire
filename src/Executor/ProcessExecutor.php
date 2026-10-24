<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Executor;

use Closure;
use Infocyph\Runwire\CancellationSubscription;
use Infocyph\Runwire\CancellationToken;
use Infocyph\Runwire\Exception\CancelledException;
use Infocyph\Runwire\Executor\Internal\ExecutorIpcTrait;
use Infocyph\Runwire\Executor\Internal\ExecutorPendingQueueTrait;
use Infocyph\Runwire\Loop\LoopInterface;
use Infocyph\Runwire\RequestDeadline;
use Infocyph\Runwire\Runtime\Enum\CancellationReason;
use InvalidArgumentException;
use LogicException;
use OverflowException;
use RuntimeException;
use SplQueue;
use Throwable;

/**
 * Bounded nonblocking IPC executor for DNS and filesystem operations.
 *
 * PHP subprocesses perform blocking calls; the owning event loop only handles
 * nonblocking pipe readiness. No blocking fallback or arbitrary code execution.
 */
final class ProcessExecutor
{
    use ExecutorIpcTrait;
    use ExecutorPendingQueueTrait;

    private const int IO_CHUNK_BYTES = 8192;

    private const int MAX_PACKET_BYTES = 1_600_000;

    /** @var SplQueue<int> */
    private readonly SplQueue $pending;

    private readonly string $phpBinary;

    private int $cancelled = 0;

    private bool $closed = false;

    private int $completed = 0;

    /** @var list<Closure> */
    private array $drainCallbacks = [];

    private int $failed = 0;

    /** @var array<int, array{packet:string, callback:Closure, worker:?int, timer:?int, subscription:?CancellationSubscription}> */
    private array $jobs = [];

    private int $nextJobId = 1;

    private int $nextWorkerId = 1;

    private int $peakPending = 0;

    private bool $pumpScheduled = false;

    private int $submitted = 0;

    /** @var array<int, array{process:resource, input:resource, output:resource, readWatcher:int, writeWatcher:?int, outputBuffer:string, inputBuffer:string, job:?int}> */
    private array $workers = [];

    /** Create an executor with bounded workers and pending jobs. */
    public function __construct(
        private readonly LoopInterface $loop,
        private readonly int $maxWorkers = 2,
        private readonly int $maxQueue = 64,
        ?string $phpBinary = null,
    ) {
        if ($maxWorkers < 1 || $maxWorkers > 64 || $maxQueue < 0 || $maxQueue > 10_000) {
            throw new InvalidArgumentException('Executor worker/queue limits are invalid.');
        }
        $this->phpBinary = $phpBinary ?? PHP_BINARY;
        if ($this->phpBinary === '' || !is_file($this->phpBinary) || !is_executable($this->phpBinary)
            || DIRECTORY_SEPARATOR === '\\' || !function_exists('proc_open')
            || !function_exists('proc_terminate') || !function_exists('proc_close')) {
            throw new RuntimeException('A usable PHP CLI binary and nonblocking process pipes are required for the executor.');
        }
        $this->pending = new SplQueue();
    }

    /** Cancel queued or active work and complete the callback exactly once. */
    public function cancel(
        int $id,
        CancellationReason $reason = CancellationReason::HOST_CANCELLED,
    ): bool {
        $job = $this->jobs[$id] ?? null;
        if ($job === null) {
            return false;
        }

        $error = new CancelledException($reason);
        if ($job['worker'] !== null) {
            $this->retire($job['worker'], $error);
        } else {
            $this->settle($id, null, $error);
        }

        return true;
    }

    /**
     * Stop all work, release watchers/pipes/processes and reject outstanding jobs.
     */
    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        foreach (array_keys($this->workers) as $workerId) {
            $this->retire($workerId, new CancelledException(CancellationReason::WORKER_SHUTDOWN));
        }
        foreach (array_keys($this->jobs) as $id) {
            $this->settle($id, null, new CancelledException(CancellationReason::WORKER_SHUTDOWN));
        }
        $this->notifyDrained();
    }

    /** @return array{submitted:int, completed:int, failed:int, cancelled:int, active:int, queued:int, workers:int, peakQueued:int} */
    public function diagnostics(): array
    {
        $active = 0;
        foreach ($this->workers as $worker) {
            $active += (int) ($worker['job'] !== null);
        }

        return [
            'submitted' => $this->submitted,
            'completed' => $this->completed,
            'failed' => $this->failed,
            'cancelled' => $this->cancelled,
            'active' => $active,
            'queued' => count($this->jobs) - $active,
            'workers' => count($this->workers),
            'peakQueued' => $this->peakPending,
        ];
    }

    /**
     * @param callable():void $callback
     */
    public function onDrained(callable $callback): void
    {
        $this->drainCallbacks[] = Closure::fromCallable($callback);
        $this->notifyDrained();
    }

    /**
     * @param array<string,mixed> $arguments
     * @param callable(mixed, ?Throwable):void $completion
     */
    public function submit(
        string $operation,
        array $arguments,
        callable $completion,
        ?CancellationToken $token = null,
        ?RequestDeadline $deadline = null,
    ): int {
        if ($this->closed) {
            throw new LogicException('Executor is closed.');
        }
        if (!in_array($operation, ['dns', 'quic_probe', 'quic_session', 'read', 'write', 'stat', 'rename', 'unlink', 'sleep'], true)) {
            throw new InvalidArgumentException('Unsupported executor operation.');
        }
        if (count($this->jobs) >= $this->maxWorkers + $this->maxQueue) {
            throw new OverflowException('Executor capacity exhausted.');
        }
        if ($this->nextJobId === PHP_INT_MAX) {
            throw new OverflowException('Executor job identifiers exhausted.');
        }

        $id = $this->nextJobId++;
        $packet = json_encode(
            ['id' => $id, 'op' => $operation, 'args' => $arguments],
            JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE,
        ) . "\n";
        if (strlen($packet) > self::MAX_PACKET_BYTES) {
            throw new InvalidArgumentException('Executor payload exceeds its bounded IPC limit.');
        }

        $this->jobs[$id] = [
            'packet' => $packet,
            'callback' => Closure::fromCallable($completion),
            'worker' => null,
            'timer' => null,
            'subscription' => null,
        ];
        $this->pending->enqueue($id);
        ++$this->submitted;
        $this->peakPending = max($this->peakPending, $this->queuedCount());

        $this->attachCancellation($id, $token);
        $this->attachDeadline($id, $deadline, $token);

        $this->schedulePump();

        return $id;
    }

    private function attachCancellation(int $id, ?CancellationToken $token): void
    {
        if ($token === null) {
            return;
        }
        $subscription = $token->onCancel(function (CancellationToken $cancelled) use ($id): void {
            $this->cancel($id, $cancelled->reason() ?? CancellationReason::HOST_CANCELLED);
        });
        if (array_key_exists($id, $this->jobs)) {
            $this->jobs[$id]['subscription'] = $subscription;
        } else {
            $subscription->unsubscribe();
        }
    }

    private function attachDeadline(int $id, ?RequestDeadline $deadline, ?CancellationToken $token): void
    {
        if (!array_key_exists($id, $this->jobs)) {
            return;
        }
        $inherited = $token?->deadline();
        if ($inherited?->monotonicNanoseconds !== null
            && ($deadline?->monotonicNanoseconds === null
                || $inherited->monotonicNanoseconds < $deadline->monotonicNanoseconds)) {
            $deadline = $inherited;
        }
        $remaining = $deadline?->remainingSeconds();
        if ($remaining !== null) {
            $this->jobs[$id]['timer'] = $this->loop->delay($remaining, function () use ($id): void {
                $this->expire($id);
            });
        }
    }

    private function availableWorker(): ?int
    {
        $available = array_find_key($this->workers, static fn(array $worker): bool => $worker['job'] === null);
        if ($available !== null) {
            return $available;
        }
        if (count($this->workers) >= $this->maxWorkers) {
            return null;
        }

        return $this->startWorker();
    }

    private function expire(int $id): void
    {
        if (!array_key_exists($id, $this->jobs)) {
            return;
        }
        $error = new CancelledException(CancellationReason::DEADLINE_EXCEEDED);
        $worker = $this->jobs[$id]['worker'];
        if ($worker !== null) {
            $this->retire($worker, $error);
        } else {
            $this->settle($id, null, $error);
        }
    }

    private function nextQueuedJob(): ?int
    {
        while (!$this->pending->isEmpty()) {
            $id = $this->pending->dequeue();
            if (array_key_exists($id, $this->jobs) && $this->jobs[$id]['worker'] === null) {
                return $id;
            }
        }

        return null;
    }

    private function notifyDrained(): void
    {
        if ($this->jobs !== [] || $this->drainCallbacks === []) {
            return;
        }
        $callbacks = $this->drainCallbacks;
        $this->drainCallbacks = [];
        foreach ($callbacks as $callback) {
            $this->loop->defer(static function () use ($callback): void {
                $callback();
            });
        }
    }

    private function pump(): void
    {
        if ($this->closed) {
            return;
        }
        while (!$this->pending->isEmpty()) {
            try {
                $available = $this->availableWorker();
            } catch (Throwable $error) {
                $jobId = $this->nextQueuedJob();
                if ($jobId !== null) {
                    $this->settle($jobId, null, $error);
                }

                return;
            }
            if ($available === null) {
                return;
            }
            $jobId = $this->nextQueuedJob();
            if ($jobId === null) {
                return;
            }

            $job = $this->jobs[$jobId];
            $job['worker'] = $available;
            $this->jobs[$jobId] = $job;
            $worker = $this->workers[$available];
            $worker['job'] = $jobId;
            $worker['inputBuffer'] = $job['packet'];
            $worker['outputBuffer'] = '';
            $worker['writeWatcher'] = $this->loop->onWritable(
                $worker['input'],
                function () use ($available): void {
                    $this->writeWorker($available);
                },
            );
            $this->workers[$available] = $worker;
        }
    }

    private function queuedCount(): int
    {
        $queued = 0;
        foreach ($this->jobs as $job) {
            $queued += (int) ($job['worker'] === null);
        }

        return $queued;
    }

    private function schedulePump(): void
    {
        if ($this->closed || $this->pumpScheduled) {
            return;
        }
        $this->pumpScheduled = true;
        $this->loop->defer(function (): void {
            $this->pumpScheduled = false;
            $this->pump();
        });
    }

    private function settle(int $id, mixed $value, ?Throwable $error): void
    {
        $job = $this->jobs[$id] ?? null;
        if ($job === null) {
            return;
        }
        unset($this->jobs[$id]);
        if ($job['worker'] === null) {
            $this->compactPendingQueue();
        }
        $job['subscription']?->unsubscribe();
        if ($job['timer'] !== null) {
            $this->loop->cancel($job['timer']);
        }
        if ($error instanceof CancelledException) {
            ++$this->cancelled;
        } elseif ($error !== null) {
            ++$this->failed;
        } else {
            ++$this->completed;
        }

        try {
            ($job['callback'])($value, $error);
        } finally {
            $this->notifyDrained();
            $this->schedulePump();
        }
    }
}
