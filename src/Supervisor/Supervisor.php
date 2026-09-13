<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Supervisor;

use Infocyph\Runwire\Control\ControlOptions;
use Infocyph\Runwire\Control\ControlServer;
use Infocyph\Runwire\Exception\SupervisorException;
use Infocyph\Runwire\Loop\LoopInterface;
use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Supervisor\Enum\ChildExitAction;
use Infocyph\Runwire\Supervisor\Enum\ShutdownReason;
use Infocyph\Runwire\Supervisor\Enum\SupervisorEventType;
use Infocyph\Runwire\Supervisor\Enum\WorkerExitReason;
use Infocyph\Runwire\Supervisor\Enum\WorkerState;
use Infocyph\Runwire\Supervisor\Internal\ChildExitTransition;
use Infocyph\Runwire\Supervisor\Internal\ChildReaper;
use Infocyph\Runwire\Supervisor\Internal\ChildRecord;
use Infocyph\Runwire\Supervisor\Internal\ChildSet;
use Infocyph\Runwire\Supervisor\Internal\LifecycleEmitter;
use Infocyph\Runwire\Supervisor\Internal\ReadinessChannel;
use Infocyph\Runwire\Supervisor\Internal\ReloadCoordinator;
use Infocyph\Runwire\Supervisor\Internal\RestartCoordinator;
use Infocyph\Runwire\Supervisor\Internal\SignalBridge;
use Infocyph\Runwire\Supervisor\Internal\SupervisorStatusBuilder;
use Infocyph\Runwire\Supervisor\Internal\WorkerChildRuntime;
use Infocyph\Runwire\Supervisor\Internal\WorkerLifecycleCoordinator;
use LogicException;

final class Supervisor
{
    private const int NANOS_PER_SECOND = 1_000_000_000;

    private readonly LifecycleEmitter $events;

    private readonly LoopInterface $loop;

    private readonly int $masterPid;

    private readonly ReloadCoordinator $reloadCoordinator;

    private readonly RestartCoordinator $restartCoordinator;

    private readonly string $runtimeId;

    private readonly SignalBridge $signalBridge;

    private readonly WorkerLifecycleCoordinator $workerLifecycleCoordinator;

    /** @var array<int, ChildRecord> */
    private array $children = [];

    private ?ControlOptions $controlOptions = null;

    private ?ControlServer $controlServer = null;

    /** @var array<string, array<int, int>> */
    private array $currentSlots = [];

    /** @var array<string, int> */
    private array $exitReasonCounts;

    private ?SupervisorException $failure = null;

    /** @var array<string, WorkerGroup> */
    private array $groups = [];

    private bool $running = false;

    private bool $started = false;

    private ?int $startedAtNs = null;

    private ?float $startedAtUnix = null;

    private bool $stopping = false;

    public function __construct(?LoopInterface $loop = null, ?ReloadPolicy $reloadPolicy = null)
    {
        $this->loop = $loop ?? new SelectLoop();
        $this->masterPid = posix_getpid();
        $this->runtimeId = bin2hex(random_bytes(16));
        $this->signalBridge = new SignalBridge($this->loop);
        $this->events = new LifecycleEmitter();
        $this->exitReasonCounts = self::reasonCounters();
        $this->reloadCoordinator = new ReloadCoordinator(
            $reloadPolicy ?? new ReloadPolicy(),
            $this->spawnWorker(...),
            $this->stopChild(...),
            $this->emit(...),
            fn(string $key): mixed => $this->restartCoordinator->cancel($key),
            fn(string $group, int $slot): int => $this->restartCoordinator->count($group, $slot),
            $this->now(...),
        );
        $this->restartCoordinator = new RestartCoordinator(
            $this->loop,
            $this->reloadCoordinator,
            $this->spawnWorker(...),
            $this->emit(...),
            $this->fail(...),
            $this->now(...),
            fn(): bool => $this->stopping,
        );
        $this->workerLifecycleCoordinator = new WorkerLifecycleCoordinator(
            $this->loop,
            $this->reloadCoordinator,
            $this->emitWorker(...),
            $this->stopChild(...),
        );
    }

    public function control(ControlOptions $options): self
    {
        if ($this->started) {
            throw new LogicException('Supervisor topology is frozen after run() starts.');
        }

        $this->controlOptions = $options;

        return $this;
    }

    public function group(WorkerGroup $group): self
    {
        if ($this->started) {
            throw new LogicException('Supervisor topology is frozen after run() starts.');
        }
        if (isset($this->groups[$group->name])) {
            throw new LogicException(sprintf('Worker group "%s" is already registered.', $group->name));
        }

        $this->groups[$group->name] = $group;
        $this->restartCoordinator->register($group);

        return $this;
    }

    /** @param callable(SupervisorEvent): void $listener */
    public function onEvent(callable $listener): self
    {
        $this->events->listen($listener);

        return $this;
    }

    public function recycle(string $group, int $slot): bool
    {
        $definition = $this->groups[$group] ?? null;
        if ($definition === null) {
            throw new LogicException(sprintf('Unknown worker group "%s".', $group));
        }
        if ($slot < 0 || $slot >= $definition->count) {
            throw new LogicException(sprintf('Worker slot %d is outside group "%s".', $slot, $group));
        }
        if (!$this->running || $this->stopping || $this->reloadCoordinator->reloading()) {
            return false;
        }

        $oldPid = $this->currentSlots[$group][$slot] ?? null;
        $old = $oldPid === null ? null : ($this->children[$oldPid] ?? null);
        if ($old === null || !$old->state->serving() || ChildSet::hasReplacementFor($this->children, $oldPid)) {
            return false;
        }

        $this->emit(new SupervisorEvent(
            SupervisorEventType::WORKER_RECYCLE_STARTED,
            $this->now(),
            group: $group,
            slot: $slot,
            pid: $oldPid,
            generation: $old->generation,
        ));
        $this->spawnWorker(
            group: $definition,
            slot: $slot,
            generation: $old->generation,
            restartCount: $this->restartCoordinator->count($group, $slot),
            replacesPid: $oldPid,
            setCurrent: false,
            recycleReplacement: true,
        );

        return true;
    }

    public function reload(): void
    {
        $this->reloadCoordinator->request(
            $this->running,
            $this->stopping,
            $this->groups,
            $this->currentSlots,
            $this->children,
        );
    }

    public function run(): void
    {
        if ($this->started) {
            throw new LogicException('A Supervisor instance can only be run once.');
        }
        if ($this->groups === []) {
            throw new LogicException('At least one worker group is required.');
        }

        $this->started = true;
        $this->running = true;
        $this->startedAtNs = (int) hrtime(true);
        $this->startedAtUnix = microtime(true);
        $this->emit(new SupervisorEvent(SupervisorEventType::SUPERVISOR_STARTING, $this->now()));

        try {
            if ($this->controlOptions !== null) {
                $this->controlServer = new ControlServer($this->controlOptions);
                $this->controlServer->open($this->loop, $this);
            }
            $this->signalBridge->open($this->processSignals(...));
            $this->spawnInitialWorkers();
            $this->loop->run();
        } catch (SupervisorException $exception) {
            $this->failure ??= $exception;
        } finally {
            $this->running = false;
            $this->forceCleanupChildren();
            $this->signalBridge->close();
            $this->controlServer?->close();
            $this->controlServer = null;
            $this->emit(new SupervisorEvent(SupervisorEventType::SUPERVISOR_STOPPED, $this->now()));
        }

        if ($this->failure !== null) {
            throw $this->failure;
        }
    }

    public function status(): SupervisorStatus
    {
        return SupervisorStatusBuilder::build(
            runtimeId: $this->runtimeId,
            masterPid: $this->masterPid,
            startedAtNs: $this->startedAtNs,
            startedAtUnix: $this->startedAtUnix,
            running: $this->running,
            stopping: $this->stopping,
            reloading: $this->reloadCoordinator->reloading(),
            reloadQueued: $this->reloadCoordinator->queued(),
            generation: $this->reloadCoordinator->generation(),
            children: $this->children,
            currentSlots: $this->currentSlots,
            pendingRestartCount: $this->restartCoordinator->pendingCount(),
            lifecycleListenerFailures: $this->events->listenerFailures(),
            generationReady: $this->reloadCoordinator->generationReady(),
            reloadFailed: $this->reloadCoordinator->failed(),
            exitReasonCounts: $this->exitReasonCounts,
            restartReasonCounts: $this->restartCoordinator->reasonCounts(),
        );
    }

    public function stop(
        bool $force = false,
        ShutdownReason $reason = ShutdownReason::SUPERVISOR_STOP,
    ): void {
        if (!$this->running) {
            return;
        }
        if ($this->stopping) {
            if ($force) {
                foreach ($this->children as $record) {
                    $this->stopChild($record, WorkerState::STOPPING, true, $reason);
                }
            }

            return;
        }

        $this->stopping = true;
        $this->reloadCoordinator->resetForStop();
        $this->emit(new SupervisorEvent(SupervisorEventType::SUPERVISOR_STOPPING, $this->now()));
        $this->restartCoordinator->cancelAll();
        foreach ($this->children as $record) {
            $this->stopChild($record, WorkerState::STOPPING, $force, $reason);
        }
        if ($this->children === []) {
            $this->loop->stop();
        }
    }

    /** @return array<string, int> */
    private static function reasonCounters(): array
    {
        $counts = [];
        foreach (WorkerExitReason::cases() as $reason) {
            $counts[$reason->value] = 0;
        }

        return $counts;
    }

    private function emit(SupervisorEvent $event): void
    {
        $this->events->emit($event);
    }

    private function emitWorker(
        SupervisorEventType $type,
        ChildRecord $record,
        ?int $exitCode = null,
        ?int $termSignal = null,
        ?bool $expected = null,
    ): void {
        $this->emit(new SupervisorEvent(
            type: $type,
            atMonotonic: $this->now(),
            group: $record->group->name,
            slot: $record->slot,
            pid: $record->pid,
            generation: $record->generation,
            state: $record->state,
            restartCount: $record->restartCount,
            exitCode: $exitCode,
            termSignal: $termSignal,
            expected: $expected,
            replacesPid: $record->replacesPid,
            shutdownReason: $record->shutdownReason,
            exitReason: $record->exitReason,
        ));
    }

    private function fail(SupervisorException $exception): void
    {
        $this->failure ??= $exception;
        $this->stop(reason: ShutdownReason::FATAL_RUNTIME_ERROR);
    }

    private function forceCleanupChildren(): void
    {
        $this->restartCoordinator->cancelAll();
        foreach ($this->children as $record) {
            posix_kill($record->pid, SIGKILL);
        }
        foreach (array_keys($this->children) as $pid) {
            ChildReaper::waitFor($pid);
        }
        foreach ($this->children as $record) {
            ReadinessChannel::close($this->loop, $record);
            if ($record->killTimerId !== null) {
                $this->loop->cancel($record->killTimerId);
            }
        }

        $this->children = [];
        $this->currentSlots = [];
    }

    private function handleChildExit(int $pid, int $status): void
    {
        $record = $this->children[$pid] ?? null;
        if ($record === null) {
            return;
        }

        ReadinessChannel::close($this->loop, $record);
        if ($record->killTimerId !== null) {
            $this->loop->cancel($record->killTimerId);
            $record->killTimerId = null;
        }

        $exitCode = ChildReaper::exitCode($status);
        $termSignal = ChildReaper::termSignal($status);
        $transition = ChildExitTransition::evaluate(
            record: $record,
            exitCode: $exitCode,
            termSignal: $termSignal,
            hasReplacement: ChildSet::hasReplacementFor($this->children, $pid),
            supervisorStopping: $this->stopping,
            childrenEmptyAfterRemoval: count($this->children) === 1,
        );
        ++$this->exitReasonCounts[$transition->reason->value];
        if ($transition->plannedRecycle) {
            $this->emitWorker(SupervisorEventType::WORKER_RECYCLE_STARTED, $record);
        }
        $this->emitWorker(
            SupervisorEventType::WORKER_EXITED,
            $record,
            exitCode: $exitCode,
            termSignal: $termSignal,
            expected: $record->expectedStop,
        );

        unset($this->children[$pid]);
        if (($this->currentSlots[$record->group->name][$record->slot] ?? null) === $pid) {
            unset($this->currentSlots[$record->group->name][$record->slot]);
        }
        $replacement = $this->replacementFor($pid);
        if ($replacement !== null && $replacement->replacesPid === $pid) {
            $replacement->replacesPid = null;
        }
        if ($this->reloadCoordinator->reloading() && $record->group->reloadable) {
            $this->reloadCoordinator->completeSlot(
                $record->group->name,
                $record->slot,
                $this->groups,
                $this->currentSlots,
                $this->children,
                $pid,
            );
        }

        $this->performExitAction($transition->action, $record);
    }

    /** @param resource $stream */
    private function handleReadyStream(int $pid, mixed $stream): void
    {
        $record = $this->children[$pid] ?? null;
        if ($record === null) {
            return;
        }

        $payload = fread($stream, 8_192);
        if (is_string($payload) && $payload !== '') {
            $record->lifecycleBuffer .= $payload;
            while (($newline = strpos($record->lifecycleBuffer, "\n")) !== false) {
                $message = substr($record->lifecycleBuffer, 0, $newline);
                $record->lifecycleBuffer = substr($record->lifecycleBuffer, $newline + 1);
                $this->workerLifecycleCoordinator->handle(
                    $record,
                    $message,
                    $this->children,
                    $this->currentSlots,
                    $this->groups,
                );
            }
        }
        if (feof($stream)) {
            ReadinessChannel::close($this->loop, $record);
        }
    }

    private function normalizeChildProcess(): void
    {
        $this->controlServer?->closeInheritedInChild();
        $this->signalBridge->normalizeChild();
        ReadinessChannel::closeInherited($this->children);
    }

    private function now(): float
    {
        return hrtime(true) / self::NANOS_PER_SECOND;
    }

    private function performExitAction(ChildExitAction $action, ChildRecord $record): void
    {
        match ($action) {
            ChildExitAction::CHECK_RELOAD => $this->reloadCoordinator->checkCompletion(
                $this->groups,
                $this->currentSlots,
                $this->children,
            ),
            ChildExitAction::NONE => null,
            ChildExitAction::RESTART => $this->restartCoordinator->schedule(
                $record,
                $this->currentSlots,
                $this->children,
            ),
            ChildExitAction::SPAWN_RECYCLE => $this->spawnWorker(
                group: $record->group,
                slot: $record->slot,
                generation: $record->generation,
                restartCount: $this->restartCoordinator->count($record->group->name, $record->slot),
                replacesPid: null,
                setCurrent: true,
            ),
            ChildExitAction::STOP_LOOP => $this->loop->stop(),
        };
    }

    /** @param list<int> $signals */
    private function processSignals(array $signals): void
    {
        if (in_array(SIGCHLD, $signals, true)) {
            $this->reapChildren();
        }
        if (in_array(SIGTERM, $signals, true) || in_array(SIGINT, $signals, true)) {
            $this->stop();

            return;
        }
        if (in_array(SIGHUP, $signals, true)) {
            $this->reload();
        }
    }

    private function reapChildren(): void
    {
        ChildReaper::reap(
            $this->handleChildExit(...),
            $this->reconcileNoChildren(...),
        );
    }

    private function reconcileNoChildren(): void
    {
        if ($this->children === []) {
            return;
        }

        $orphans = $this->children;
        $this->children = [];
        $this->currentSlots = [];
        foreach ($orphans as $record) {
            ReadinessChannel::close($this->loop, $record);
            if ($record->killTimerId !== null) {
                $this->loop->cancel($record->killTimerId);
            }
        }
        if ($this->stopping) {
            $this->loop->stop();

            return;
        }

        $this->fail(new SupervisorException('Kernel child state diverged from the supervisor child table.'));
    }

    private function replacementFor(int $pid): ?ChildRecord
    {
        foreach ($this->children as $record) {
            if ($record->replacesPid === $pid) {
                return $record;
            }
        }

        return null;
    }

    /** @param resource $childReady */
    private function runChild(WorkerGroup $group, int $slot, int $generation, mixed $childReady): never
    {
        $this->normalizeChildProcess();
        WorkerChildRuntime::run($group, $slot, $generation, $childReady);
    }

    private function spawnInitialWorkers(): void
    {
        foreach (ChildSet::slots($this->groups) as [$group, $slot]) {
            $this->spawnWorker($group, $slot, $this->reloadCoordinator->generation(), 0, null, true);
        }
    }

    private function spawnWorker(
        WorkerGroup $group,
        int $slot,
        int $generation,
        int $restartCount,
        ?int $replacesPid,
        bool $setCurrent,
        bool $recycleReplacement = false,
        ?float $readyTimeoutSeconds = null,
    ): void {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($pair === false) {
            throw new SupervisorException('Unable to create worker readiness channel.');
        }

        [$parentReady, $childReady] = $pair;
        if (!stream_set_blocking($parentReady, false)) {
            fclose($parentReady);
            fclose($childReady);

            throw new SupervisorException('Unable to configure worker readiness channel.');
        }

        $pid = pcntl_fork();
        if ($pid === -1) {
            fclose($parentReady);
            fclose($childReady);

            throw new SupervisorException(sprintf('Unable to fork worker %s[%d].', $group->name, $slot));
        }
        if ($pid === 0) {
            fclose($parentReady);
            $this->runChild($group, $slot, $generation, $childReady);
        }

        fclose($childReady);
        $readyWatcherId = $this->loop->onReadable(
            $parentReady,
            function ($stream) use ($pid): void {
                $this->handleReadyStream($pid, $stream);
            },
        );
        $timeout = $readyTimeoutSeconds ?? $group->readyTimeoutSeconds;
        $readyTimerId = $this->loop->delay(
            $timeout,
            function () use ($pid): void {
                $record = $this->children[$pid] ?? null;
                if ($record === null || $record->state !== WorkerState::STARTING) {
                    return;
                }

                $record->state = WorkerState::UNHEALTHY;
                $record->exitReason = WorkerExitReason::READINESS_TIMEOUT;
                $this->emitWorker(SupervisorEventType::WORKER_UNHEALTHY, $record);
                posix_kill($pid, SIGKILL);
            },
        );
        $record = new ChildRecord(
            group: $group,
            slot: $slot,
            pid: $pid,
            generation: $generation,
            restartCount: $restartCount,
            startedAtNs: (int) hrtime(true),
            readyStream: $parentReady,
            readyWatcherId: $readyWatcherId,
            readyTimerId: $readyTimerId,
            replacesPid: $replacesPid,
            recycleReplacement: $recycleReplacement,
        );
        $this->children[$pid] = $record;
        $this->emit(new SupervisorEvent(
            SupervisorEventType::WORKER_SPAWNED,
            $this->now(),
            group: $group->name,
            slot: $slot,
            pid: $pid,
            generation: $generation,
            state: WorkerState::STARTING,
            restartCount: $restartCount,
            replacesPid: $replacesPid,
        ));
        if ($setCurrent) {
            $this->currentSlots[$group->name][$slot] = $pid;
        }
    }

    private function stopChild(
        ChildRecord $record,
        WorkerState $state,
        bool $force,
        ShutdownReason $reason,
        ?float $timeoutSeconds = null,
    ): void {
        $firstRequest = !$record->expectedStop;
        $record->expectedStop = true;
        $record->state = $state;
        $record->shutdownReason ??= $reason;
        if ($firstRequest) {
            if (is_resource($record->readyStream)) {
                fwrite($record->readyStream, 'S:' . $record->shutdownReason->value . "\n");
            }
            $this->emitWorker(SupervisorEventType::WORKER_STOP_REQUESTED, $record);
        }

        posix_kill($record->pid, $force ? SIGKILL : SIGTERM);
        if ($force || $record->killTimerId !== null) {
            return;
        }

        $record->killTimerId = $this->loop->delay(
            $timeoutSeconds ?? $record->group->shutdownTimeoutSeconds,
            function () use ($record): void {
                if (isset($this->children[$record->pid])) {
                    posix_kill($record->pid, SIGKILL);
                }
            },
        );
    }
}
