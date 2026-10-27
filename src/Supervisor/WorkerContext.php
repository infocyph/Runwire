<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Supervisor;

use Infocyph\Runwire\Coroutine\CoroutineDiagnosticsSnapshot;
use Infocyph\Runwire\Coroutine\CoroutineScope;
use Infocyph\Runwire\Coroutine\Task;
use Infocyph\Runwire\Executor\ProcessExecutor;
use Infocyph\Runwire\Loop\LoopInterface;
use Infocyph\Runwire\Metrics\RuntimeMetricsSnapshot;
use Infocyph\Runwire\Network\Internal\ByteBudget;
use Infocyph\Runwire\Network\BoundedConnectionPool;
use Infocyph\Runwire\Network\Connection;
use Infocyph\Runwire\Network\Dialer;
use Infocyph\Runwire\Network\HappyEyeballsDialer;
use Infocyph\Runwire\Network\QuicClientSession;
use Infocyph\Runwire\Runtime\AdmissionPolicy;
use Infocyph\Runwire\Runtime\Internal\WorkerRecycleState;
use Infocyph\Runwire\Supervisor\Enum\ShutdownReason;
use Infocyph\Runwire\Supervisor\Enum\WorkerRole;
use Infocyph\Runwire\Supervisor\Internal\PeriodicTaskRegistry;
use Infocyph\Runwire\Supervisor\Internal\WorkerCoroutineScope;
use LogicException;
use RuntimeException;

/**
 * Exposes worker identity, lifecycle signaling, recycling, periodic work, and background coroutine controls.
 */
final class WorkerContext
{
    private const int MAX_DIAGNOSTIC_MESSAGE_BYTES = 6_144;

    public readonly ByteBudget $bufferBudget;

    private readonly PeriodicTaskRegistry $periodicTasks;

    private readonly WorkerRecycleState $recycleState;

    private int $activeRequests = 0;

    /** @var array<int, ProcessExecutor> */
    private array $ownedExecutors = [];

    /** @var array<int, BoundedConnectionPool> */
    private array $ownedPools = [];

    /** @var array<int, Connection> */
    private array $ownedConnections = [];

    /** @var array<int, Dialer> */
    private array $ownedDialers = [];

    /** @var array<int, HappyEyeballsDialer> */
    private array $ownedHappyDialers = [];

    /** @var array<int, QuicClientSession> */
    private array $ownedQuicSessions = [];

    private ?WorkerCoroutineScope $backgroundCoroutines = null;

    private ?LoopInterface $controlLoop = null;

    private ?int $controlWatcher = null;

    private string $lifecycleBuffer = '';

    private bool $ready = false;

    private bool $recycling = false;

    private ShutdownReason $shutdownReason = ShutdownReason::SUPERVISOR_STOP;

    private bool $stopping = false;

    /** @var resource|null */
    private mixed $stopRead = null;

    /** @var resource|null */
    private mixed $stopWrite = null;

    /**
     * Create a worker context optionally bound to a parent lifecycle channel.
     *
     * @param resource|null $readyStream
     */
    public function __construct(
        public readonly string $group,
        public readonly int $slot,
        public readonly int $generation,
        public readonly int $pid,
        public readonly int $parentPid,
        private readonly mixed $readyStream = null,
        public readonly WorkerRecyclePolicy $recyclePolicy = new WorkerRecyclePolicy(),
        public readonly AdmissionPolicy $admissionPolicy = new AdmissionPolicy(),
        public readonly WorkerRole $role = WorkerRole::CUSTOM,
    ) {
        if ($this->readyStream !== null) {
            if (!is_resource($this->readyStream) || !stream_set_blocking($this->readyStream, false)) {
                throw new RuntimeException('Unable to configure worker lifecycle channel.');
            }
        }

        $this->bufferBudget = new ByteBudget($this->admissionPolicy->maxQueuedBytes);
        $this->periodicTasks = new PeriodicTaskRegistry();
        $this->recycleState = new WorkerRecycleState(
            $this->recyclePolicy,
            seed: $pid ^ ($slot << 8) ^ ($generation << 16),
        );
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if (!is_array($pair) || count($pair) !== 2) {
            throw new RuntimeException('Unable to create worker stop wake channel.');
        }

        [$this->stopRead, $this->stopWrite] = $pair;
        if (!stream_set_blocking($this->stopRead, false) || !stream_set_blocking($this->stopWrite, false)) {
            $this->close();

            throw new RuntimeException('Unable to configure worker stop wake channel.');
        }
    }

    /**
     * Determine whether the worker still accepts new background work.
     */
    public function acceptingBackgroundWork(): bool
    {
        return !$this->stopping;
    }

    /**
     * Attach the worker event loop and initialize background coroutine support when applicable.
     */
    public function attachLoop(LoopInterface $loop, float $backgroundShutdownGraceSeconds = 10.0): void
    {
        $this->periodicTasks->attach($loop);
        $this->attachControlWatcher($loop);
        if (!$this->role->background()) {
            return;
        }
        if ($this->backgroundCoroutines !== null) {
            if (!$this->backgroundCoroutines->ownsLoop($loop)) {
                throw new LogicException('Worker coroutine scope cannot switch event loops after attachment.');
            }

            return;
        }

        $this->backgroundCoroutines = new WorkerCoroutineScope(
            $loop,
            $backgroundShutdownGraceSeconds,
            function (): void {
                $this->markUnhealthy();
                $this->requestStop(ShutdownReason::FATAL_RUNTIME_ERROR);
            },
        );
    }

    /**
     * Return background coroutine diagnostics when a background scope exists.
     */
    public function backgroundCoroutineDiagnostics(): ?CoroutineDiagnosticsSnapshot
    {
        return $this->backgroundCoroutines?->diagnostics();
    }

    /**
     * Determine whether background coroutine draining exceeded its grace period.
     */
    public function backgroundDrainExpired(): bool
    {
        return $this->backgroundCoroutines?->drainExpired() ?? false;
    }

    /**
     * Return the number of active background coroutine tasks.
     */
    public function backgroundTaskCount(): int
    {
        return $this->backgroundCoroutines?->activeTaskCount() ?? 0;
    }

    /**
     * Register an executor whose lifetime belongs to this worker.
     *
     * The caller explicitly transfers shutdown responsibility; borrowed
     * executors must not be registered.
     */
    public function ownExecutor(ProcessExecutor $executor): void
    {
        if ($this->stopping) {
            throw new LogicException('Stopping workers cannot accept executor ownership.');
        }

        $this->ownedExecutors[spl_object_id($executor)] = $executor;
    }

    /** Explicitly transfer one connection pool's shutdown to this worker. */
    public function ownPool(BoundedConnectionPool $pool): void
    {
        if ($this->stopping) {
            throw new LogicException('Stopping workers cannot accept pool ownership.');
        }

        $this->ownedPools[spl_object_id($pool)] = $pool;
    }

    /** Take responsibility for aborting a socket still active when this worker retires. */
    public function ownConnection(Connection $connection): void
    {
        if ($this->stopping) {
            throw new LogicException('Stopping workers cannot accept connection ownership.');
        }

        $this->ownedConnections[spl_object_id($connection)] = $connection;
    }

    /** Take ownership of an underlying numeric TCP/Unix connector and pending dials. */
    public function ownDialer(Dialer $dialer): void
    {
        if ($this->stopping) {
            throw new LogicException('Stopping workers cannot accept dialer ownership.');
        }

        $this->ownedDialers[spl_object_id($dialer)] = $dialer;
    }

    /** Own a hostname-racing dialer without implicitly owning its borrowed dependencies. */
    public function ownHappyEyeballsDialer(HappyEyeballsDialer $dialer): void
    {
        if ($this->stopping) {
            throw new LogicException('Stopping workers cannot accept dialer ownership.');
        }

        $this->ownedHappyDialers[spl_object_id($dialer)] = $dialer;
    }

    /** Explicitly transfer a native QUIC session's shutdown to this worker. */
    public function ownQuicSession(QuicClientSession $session): void
    {
        if ($this->stopping) {
            throw new LogicException('Stopping workers cannot accept QUIC session ownership.');
        }

        $this->ownedQuicSessions[spl_object_id($session)] = $session;
    }

    /**
     * Close worker lifecycle, stop-wake, periodic-task, and coroutine resources.
     */
    public function close(): void
    {
        // A retired generation must never advertise background-work admission.
        $this->stopping = true;
        $this->backgroundCoroutines?->close();
        $this->periodicTasks->close();
        $this->closeOwnedNetworkResources();
        $this->closeOwnedExecutors();
        if ($this->controlWatcher !== null && $this->controlLoop !== null) {
            $this->controlLoop->cancel($this->controlWatcher);
        }
        $this->controlWatcher = null;
        $this->controlLoop = null;
        if (is_resource($this->readyStream)) {
            fclose($this->readyStream);
        }

        foreach (['stopRead', 'stopWrite'] as $property) {
            if (is_resource($this->{$property})) {
                fclose($this->{$property});
            }
            $this->{$property} = null;
        }
    }

    /**
     * Drain pending bytes from the worker stop wake channel.
     */
    public function consumeStopWake(): void
    {
        if (!is_resource($this->stopRead)) {
            return;
        }

        do {
            $chunk = fread($this->stopRead, 8_192);
        } while (is_string($chunk) && $chunk !== '');
    }

    /**
     * Return the worker's latest observed memory usage.
     */
    public function currentMemoryBytes(): int
    {
        return $this->recycleState->currentMemoryBytes();
    }

    /**
     * Return the jitter-adjusted worker lifetime recycle threshold.
     */
    public function effectiveMaxLifetimeSeconds(): int
    {
        return $this->recycleState->effectiveMaxLifetimeSeconds();
    }

    /**
     * Return the jitter-adjusted request recycle threshold.
     */
    public function effectiveMaxRequests(): int
    {
        return $this->recycleState->effectiveMaxRequests();
    }

    /**
     * Register periodic worker work on the attached event loop.
     *
     * @param callable(): void $callback
     */
    public function every(string $name, float $intervalSeconds, callable $callback): PeriodicTaskHandle
    {
        return $this->periodicTasks->register($name, $intervalSeconds, $callback);
    }

    /**
     * Report the worker as unhealthy to the supervisor.
     */
    public function markUnhealthy(): void
    {
        $this->signal('U');
    }

    /**
     * Return the highest observed worker memory usage.
     */
    public function peakMemoryBytes(): int
    {
        return $this->recycleState->peakMemoryBytes();
    }

    /**
     * Signal that worker initialization is complete and traffic may be served.
     */
    public function ready(): void
    {
        if ($this->ready) {
            return;
        }

        $this->signal('R');
        $this->ready = true;
    }

    /**
     * Record request completion and request recycling when a threshold is reached.
     */
    public function recordRequestCompleted(): bool
    {
        if ($this->activeRequests > 0) {
            --$this->activeRequests;
            if ($this->activeRequests === 0 && !$this->stopping) {
                $this->signal('I');
            }
        }

        $recycle = $this->recycleState->recordRequestCompleted();
        if ($recycle) {
            $this->requestRecycle(
                $this->recycleState->recycleReason() ?? ShutdownReason::RECYCLE_REQUEST_LIMIT,
            );
        }

        return $recycle;
    }

    /**
     * Record the start of one active request and signal busy state on transition from idle.
     */
    public function recordRequestStarted(): void
    {
        ++$this->activeRequests;
        if ($this->activeRequests === 1 && !$this->stopping) {
            $this->signal('B');
        }
    }

    /**
     * Determine whether the worker is shutting down for recycling.
     */
    public function recycling(): bool
    {
        return $this->recycling;
    }

    /**
     * Report a request deadline-exceeded diagnostic to the supervisor.
     */
    public function reportDeadlineExceeded(string $requestId): void
    {
        if ($requestId === '' || strlen($requestId) > 128) {
            return;
        }

        $this->signalDiagnostic('D:' . $requestId);
    }

    /**
     * Report a serialized runtime metrics snapshot to the supervisor.
     */
    public function reportMetrics(RuntimeMetricsSnapshot $snapshot): void
    {
        $json = json_encode($snapshot->toArray(), JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            return;
        }

        $this->signalDiagnostic('M:' . $json);
    }

    /**
     * Report application warmup failure to the supervisor.
     */
    public function reportWarmupFailure(): void
    {
        $this->signal('W');
    }

    /**
     * Request an orderly worker recycle for the supplied reason.
     */
    public function requestRecycle(ShutdownReason $reason = ShutdownReason::MANUAL_RECYCLE): void
    {
        if ($this->recycling) {
            return;
        }

        $this->recycling = true;
        $this->signal('X:' . $reason->value);
        $this->requestStop($reason);
    }

    /**
     * Begin worker shutdown and stop accepting scheduled background work.
     */
    public function requestStop(?ShutdownReason $reason = null): void
    {
        if ($this->stopping) {
            return;
        }

        $controlReason = $this->readControl();
        $this->beginStop($reason ?? $controlReason ?? $this->shutdownReason);
    }

    /**
     * Return the cumulative number of completed requests handled by the worker.
     */
    public function requestsTotal(): int
    {
        return $this->recycleState->requestsTotal();
    }

    /**
     * Return the latest supervisor-provided or locally requested shutdown reason.
     */
    public function shutdownReason(): ShutdownReason
    {
        $this->readControl();

        return $this->shutdownReason;
    }

    /**
     * Spawn background coroutine work for task or service worker roles.
     *
     * @param callable(CoroutineScope): mixed $callback
     */
    public function spawnBackground(callable $callback): Task
    {
        if (!$this->role->background()) {
            throw new LogicException('Background coroutine work requires a task or service worker role.');
        }
        if ($this->stopping) {
            throw new LogicException('Worker is stopping and cannot accept background coroutine work.');
        }
        if ($this->backgroundCoroutines === null) {
            throw new LogicException('Worker event loop must be attached before spawning background coroutine work.');
        }

        return $this->backgroundCoroutines->spawn($callback);
    }

    /**
     * Determine whether worker shutdown has begun.
     */
    public function stopping(): bool
    {
        return $this->stopping;
    }

    /**
     * Return the readable stop-wake stream for event-loop integration.
     *
     * @return resource
     */
    public function stopStream(): mixed
    {
        if (!is_resource($this->stopRead)) {
            throw new RuntimeException('Worker stop wake channel is closed.');
        }

        return $this->stopRead;
    }

    private function attachControlWatcher(LoopInterface $loop): void
    {
        if (!is_resource($this->readyStream)) {
            return;
        }
        if ($this->controlWatcher !== null) {
            if ($this->controlLoop !== $loop) {
                throw new LogicException('Worker lifecycle control cannot switch event loops after attachment.');
            }

            return;
        }

        $this->controlLoop = $loop;
        $this->controlWatcher = $loop->onReadable(
            $this->readyStream,
            function (): void {
                $reason = $this->readControl();
                if ($reason !== null) {
                    $this->beginStop($reason);
                }
            },
        );
    }

    private function closeOwnedNetworkResources(): void
    {
        $connections = $this->ownedConnections;
        $dialers = $this->ownedDialers;
        $happyDialers = $this->ownedHappyDialers;
        $pools = $this->ownedPools;
        $sessions = $this->ownedQuicSessions;
        $this->ownedPools = [];
        $this->ownedConnections = [];
        $this->ownedDialers = [];
        $this->ownedHappyDialers = [];
        $this->ownedQuicSessions = [];

        foreach ($happyDialers as $dialer) {
            try {
                $dialer->close();
            } catch (\Throwable) {
                // Keep draining independent owner resources despite failed completion observers.
            }
        }
        foreach ($dialers as $dialer) {
            try {
                $dialer->close();
            } catch (\Throwable) {
                // The next connector still owns independent pending sockets.
            }
        }
        foreach ($connections as $connection) {
            try {
                $connection->abort();
            } catch (\Throwable) {
                // Continue retiring other owned sockets and sessions.
            }
        }
        foreach ($pools as $pool) {
            try {
                $pool->close();
            } catch (\Throwable) {
                // Release other worker resources even when application observers fail.
            }
        }
        foreach ($sessions as $session) {
            try {
                $session->dispose();
            } catch (\Throwable) {
                // A failed QUIC callback must not prevent other sessions closing.
            }
        }
    }

    private function closeOwnedExecutors(): void
    {
        $executors = $this->ownedExecutors;
        $this->ownedExecutors = [];
        foreach ($executors as $executor) {
            try {
                $executor->close();
            } catch (\Throwable) {
                // Application completion observers must not block other worker-owned cleanup.
            }
        }
    }

    private function beginStop(ShutdownReason $reason): void
    {
        if ($this->stopping) {
            return;
        }

        $this->shutdownReason = $reason;
        $this->stopping = true;
        $this->periodicTasks->drain();
        $this->backgroundCoroutines?->drain();
        $this->closeOwnedExecutors();
        if (is_resource($this->stopWrite)) {
            fwrite($this->stopWrite, 'S');
        }
    }

    private function readControl(): ?ShutdownReason
    {
        if (!is_resource($this->readyStream)) {
            return null;
        }

        $observed = null;
        do {
            $chunk = fread($this->readyStream, 1_024);
            if (is_string($chunk) && $chunk !== '') {
                $this->lifecycleBuffer .= $chunk;
            }
        } while (is_string($chunk) && $chunk !== '');

        while (($newline = strpos($this->lifecycleBuffer, "\n")) !== false) {
            $message = substr($this->lifecycleBuffer, 0, $newline);
            $this->lifecycleBuffer = substr($this->lifecycleBuffer, $newline + 1);
            if (!str_starts_with($message, 'S:')) {
                continue;
            }
            $reason = ShutdownReason::tryFrom(substr($message, 2));
            if ($reason !== null) {
                $this->shutdownReason = $reason;
                $observed = $reason;
            }
        }

        return $observed;
    }

    private function signal(string $message): void
    {
        if (!is_resource($this->readyStream)) {
            return;
        }

        $payload = $message . "\n";
        $written = fwrite($this->readyStream, $payload);
        if ($written !== strlen($payload)) {
            throw new RuntimeException('Unable to signal worker lifecycle state.');
        }
    }

    private function signalDiagnostic(string $message): void
    {
        if (!is_resource($this->readyStream) || strlen($message) > self::MAX_DIAGNOSTIC_MESSAGE_BYTES) {
            return;
        }

        $payload = $message . "\n";
        fwrite($this->readyStream, $payload);
    }
}
