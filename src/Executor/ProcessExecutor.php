<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Executor;

use Closure;
use Infocyph\Runwire\CancellationSubscription;
use Infocyph\Runwire\CancellationToken;
use Infocyph\Runwire\Exception\CancelledException;
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
    private const int MAX_PACKET_BYTES = 1_600_000;

    private const int IO_CHUNK_BYTES = 8192;

    private bool $closed = false;

    /** @var list<Closure> */
    private array $drainCallbacks = [];

    /** @var array<int, array{packet:string, callback:Closure, worker:?int, timer:?int, subscription:?CancellationSubscription}> */
    private array $jobs = [];

    private int $nextJobId = 1;

    private int $nextWorkerId = 1;

    private bool $pumpScheduled = false;

    /** @var SplQueue<int> */
    private SplQueue $pending;

    private int $submitted = 0;

    private int $completed = 0;

    private int $failed = 0;

    private int $cancelled = 0;

    private int $peakPending = 0;

    /** @var array<int, array{process:resource, input:resource, output:resource, readWatcher:int, writeWatcher:?int, outputBuffer:string, inputBuffer:string, job:?int}> */
    private array $workers = [];

    private readonly string $phpBinary;

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
        if (!in_array($operation, ['dns', 'read', 'stat', 'sleep'], true)) {
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

        if ($token !== null) {
            $subscription = $token->onCancel(function (CancellationToken $cancelled) use ($id): void {
                $this->cancel($id, $cancelled->reason() ?? CancellationReason::HOST_CANCELLED);
            });
            if (isset($this->jobs[$id])) {
                $this->jobs[$id]['subscription'] = $subscription;
            } else {
                $subscription->unsubscribe();
            }
        }

        if ($deadline !== null && isset($this->jobs[$id])) {
            $remaining = $deadline->remainingSeconds();
            if ($remaining !== null) {
                $this->jobs[$id]['timer'] = $this->loop->delay($remaining, function () use ($id): void {
                    $this->expire($id);
                });
            }
        }

        $this->schedulePump();

        return $id;
    }

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

    /**
     * @param callable():void $callback
     */
    public function onDrained(callable $callback): void
    {
        $this->drainCallbacks[] = Closure::fromCallable($callback);
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

    private function expire(int $id): void
    {
        if (!isset($this->jobs[$id])) {
            return;
        }
        $error = new RuntimeException('Executor operation deadline exceeded.');
        $worker = $this->jobs[$id]['worker'];
        if ($worker !== null) {
            $this->retire($worker, $error);
        } else {
            $this->settle($id, null, $error);
        }
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

    private function pump(): void
    {
        if ($this->closed) {
            return;
        }
        while (!$this->pending->isEmpty()) {
            $available = null;
            foreach ($this->workers as $id => $worker) {
                if ($worker['job'] === null) {
                    $available = $id;
                    break;
                }
            }
            if ($available === null) {
                if (count($this->workers) >= $this->maxWorkers) {
                    return;
                }
                try {
                    $available = $this->startWorker();
                } catch (Throwable $error) {
                    $jobId = $this->nextQueuedJob();
                    if ($jobId !== null) {
                        $this->settle($jobId, null, $error);
                    }

                    return;
                }
            }
            $jobId = $this->nextQueuedJob();
            if ($jobId === null) {
                return;
            }
            $this->jobs[$jobId]['worker'] = $available;
            $this->workers[$available]['job'] = $jobId;
            $this->workers[$available]['inputBuffer'] = $this->jobs[$jobId]['packet'];
            $this->workers[$available]['outputBuffer'] = '';
            $this->workers[$available]['writeWatcher'] = $this->loop->onWritable(
                $this->workers[$available]['input'],
                function () use ($available): void {
                    $this->writeWorker($available);
                },
            );
        }
    }

    private function nextQueuedJob(): ?int
    {
        while (!$this->pending->isEmpty()) {
            $id = $this->pending->dequeue();
            if (isset($this->jobs[$id]) && $this->jobs[$id]['worker'] === null) {
                return $id;
            }
        }

        return null;
    }

    private function queuedCount(): int
    {
        $queued = 0;
        foreach ($this->jobs as $job) {
            $queued += (int) ($job['worker'] === null);
        }

        return $queued;
    }

    private function startWorker(): int
    {
        $script = dirname(__DIR__, 2) . '/tools/executor-worker.php';
        $pipes = [];
        $nullDevice = DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null';
        $process = proc_open(
            [$this->phpBinary, '-d', 'display_errors=stderr', $script],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $nullDevice, 'a']],
            $pipes,
            null,
            null,
            ['bypass_shell' => true],
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start executor subprocess.');
        }
        stream_set_blocking($pipes[0], false);
        stream_set_blocking($pipes[1], false);

        $id = $this->nextWorkerId++;
        try {
            $readWatcher = $this->loop->onReadable($pipes[1], function () use ($id): void {
                $this->readWorker($id);
            });
        } catch (Throwable $error) {
            fclose($pipes[0]);
            fclose($pipes[1]);
            proc_terminate($process, 9);
            proc_close($process);

            throw $error;
        }
        $this->workers[$id] = [
            'process' => $process,
            'input' => $pipes[0],
            'output' => $pipes[1],
            'readWatcher' => $readWatcher,
            'writeWatcher' => null,
            'inputBuffer' => '',
            'outputBuffer' => '',
            'job' => null,
        ];

        return $id;
    }

    private function writeWorker(int $id): void
    {
        if (!isset($this->workers[$id])) {
            return;
        }
        $worker = &$this->workers[$id];
        $sent = @fwrite($worker['input'], substr($worker['inputBuffer'], 0, self::IO_CHUNK_BYTES));
        if ($sent === false || ($sent === 0 && feof($worker['input']))) {
            unset($worker);
            $this->retire($id, new RuntimeException('Executor subprocess input closed.'));

            return;
        }
        $worker['inputBuffer'] = substr($worker['inputBuffer'], $sent);
        if ($worker['inputBuffer'] === '' && $worker['writeWatcher'] !== null) {
            $this->loop->cancel($worker['writeWatcher']);
            $worker['writeWatcher'] = null;
        }
        unset($worker);
    }

    private function readWorker(int $id): void
    {
        if (!isset($this->workers[$id])) {
            return;
        }
        $chunk = @fread($this->workers[$id]['output'], self::IO_CHUNK_BYTES);
        if ($chunk === false || ($chunk === '' && feof($this->workers[$id]['output']))) {
            $this->retire($id, new RuntimeException('Executor subprocess exited or closed its output.'));

            return;
        }
        if ($chunk === '') {
            return;
        }
        $this->workers[$id]['outputBuffer'] .= $chunk;
        $buffer = $this->workers[$id]['outputBuffer'];
        if (strlen($buffer) > self::MAX_PACKET_BYTES) {
            $this->retire($id, new RuntimeException('Executor subprocess exceeded response limits.'));

            return;
        }
        $newline = strpos($buffer, "\n");
        if ($newline === false) {
            return;
        }
        $jobId = $this->workers[$id]['job'];
        if ($jobId === null || $newline !== strlen($buffer) - 1) {
            $this->retire($id, new RuntimeException('Unexpected executor response frame.'));

            return;
        }

        try {
            $response = json_decode(substr($buffer, 0, $newline), true, 16, JSON_THROW_ON_ERROR);
            if (!is_array($response) || ($response['id'] ?? null) !== $jobId || !is_bool($response['ok'] ?? null)) {
                throw new RuntimeException('Executor returned an invalid response.');
            }
            if ($response['ok']) {
                $value = $response['value'] ?? null;
                if (($this->jobs[$jobId]['packet'] ?? '') !== ''
                    && str_contains($this->jobs[$jobId]['packet'], '"op":"read"')) {
                    $value = is_string($value) ? base64_decode($value, true) : false;
                    if ($value === false) {
                        throw new RuntimeException('Executor returned invalid file data.');
                    }
                }
                $error = null;
            } else {
                $value = null;
                $error = new RuntimeException((string) ($response['error'] ?? 'Executor operation failed.'));
            }
        } catch (Throwable $error) {
            $this->retire($id, $error);

            return;
        }

        $this->workers[$id]['outputBuffer'] = '';
        $this->workers[$id]['job'] = null;
        $this->settle($jobId, $value, $error);
    }

    private function retire(int $id, Throwable $reason): void
    {
        $worker = $this->workers[$id] ?? null;
        if ($worker === null) {
            return;
        }
        unset($this->workers[$id]);
        $this->loop->cancel($worker['readWatcher']);
        if ($worker['writeWatcher'] !== null) {
            $this->loop->cancel($worker['writeWatcher']);
        }
        fclose($worker['input']);
        fclose($worker['output']);
        $status = proc_get_status($worker['process']);
        if ($status['running']) {
            proc_terminate($worker['process'], 9);
        }
        proc_close($worker['process']);
        if ($worker['job'] !== null) {
            $this->settle($worker['job'], null, $reason);
        }
        $this->schedulePump();
    }

    private function settle(int $id, mixed $value, ?Throwable $error): void
    {
        $job = $this->jobs[$id] ?? null;
        if ($job === null) {
            return;
        }
        unset($this->jobs[$id]);
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
}
