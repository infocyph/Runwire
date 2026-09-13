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
use Infocyph\Runwire\Supervisor\Internal\RestartTracker;
use Infocyph\Runwire\Supervisor\Internal\SignalBridge;
use Infocyph\Runwire\Supervisor\Internal\SupervisorStatusBuilder;
use Infocyph\Runwire\Supervisor\Internal\WorkerChildRuntime;
use LogicException;

final class Supervisor
{
    private const int NANOS_PER_SECOND = 1_000_000_000;

    private readonly LifecycleEmitter $events;

    private readonly LoopInterface $loop;

    private readonly int $masterPid;

    private readonly ReloadPolicy $reloadPolicy;

    private readonly RestartTracker $restartTracker;

    private readonly string $runtimeId;

    private readonly SignalBridge $signalBridge;

    /** @var array<int, ChildRecord> */
    private array $children = [];

    private ?ControlOptions $controlOptions = null;

    private ?ControlServer $controlServer = null;

    /** @var array<string, array<int, int>> */
    private array $currentSlots = [];

    /** @var array<string, int> */
    private array $exitReasonCounts;

    private ?SupervisorException $failure = null;

    private int $generation = 1;

    private bool $generationReady = false;

    /** @var array<string, WorkerGroup> */
    private array $groups = [];

    /** @var array<string, true> */
    private array $reloadActive = [];

    private bool $reloadFailed = false;

    /** @var list<array{0: string, 1: int}> */
    private array $reloadPending = [];

    private bool $reloading = false;

    private bool $reloadQueued = false;

    /** @var array<string, int> */
    private array $restartReasonCounts;

    /** @var array<string, int> */
    private array $restartTimers = [];

    private bool $running = false;

    private bool $started = false;

    private ?int $startedAtNs = null;

    private ?float $startedAtUnix = null;

    private bool $stopping = false;

    public function __construct(?LoopInterface $loop = null, ?ReloadPolicy $reloadPolicy = null)
    {
        $this->loop = $loop ?? new SelectLoop();
        $this->reloadPolicy = $reloadPolicy ?? new ReloadPolicy();
        $this->masterPid = posix_getpid();
        $this->runtimeId = bin2hex(random_bytes(16));
        $this->restartTracker = new RestartTracker();
        $this->signalBridge = new SignalBridge($this->loop);
        $this->events = new LifecycleEmitter();
        $this->exitReasonCounts = self::reasonCounters();
        $this->restartReasonCounts = self::reasonCounters();
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
        $this->restartTracker->register($group);

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
        if (!$this->running || $this->stopping || $this->reloading) {
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
            restartCount: $this->restartTracker->count($group, $slot),
            replacesPid: $oldPid,
            setCurrent: false,
            recycleReplacement: true,
        );

        return true;
    }

    public function reload(): void
    {
        if (!$this->running || $this->stopping) {
            return;
        }

        if ($this->reloading) {
            $this->reloadQueued = true;

            return;
        }

        $this->beginReload();
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
            reloading: $this->reloading,
            reloadQueued: $this->reloadQueued,
            generation: $this->generation,
            children: $this->children,
            currentSlots: $this->currentSlots,
            pendingRestartCount: count($this->restartTimers),
            lifecycleListenerFailures: $this->events->listenerFailures(),
            generationReady: $this->generationReady,
            reloadFailed: $this->reloadFailed,
            exitReasonCounts: $this->exitReasonCounts,
            restartReasonCounts: $this->restartReasonCounts,
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
        $this->reloading = false;
        $this->reloadQueued = false;
        $this->reloadPending = [];
        $this->reloadActive = [];
        $this->emit(new SupervisorEvent(SupervisorEventType::SUPERVISOR_STOPPING, $this->now()));
        $this->cancelRestartTimers();

        foreach ($this->children as $record) {
            $this->stopChild($record, WorkerState::STOPPING, $force, $reason);
        }

        if ($this->children === []) {
            $this->loop->stop();
        }
    }

    private function abortReload(ChildRecord $failed): void
    {
        if (!$this->reloading) {
            return;
        }

        $targetGeneration = $this->generation;
        foreach (array_keys($this->reloadActive) as $key) {
            if (isset($this->restartTimers[$key])) {
                $this->loop->cancel($this->restartTimers[$key]);
                unset($this->restartTimers[$key]);
            }
        }

        $this->reloading = false;
        $this->reloadQueued = false;
        $this->reloadFailed = true;
        $this->generationReady = false;
        $this->reloadPending = [];
        $this->reloadActive = [];

        foreach ($this->children as $record) {
            if ($record->generation !== $targetGeneration) {
                continue;
            }
            $currentPid = $this->currentSlots[$record->group->name][$record->slot] ?? null;
            if ($currentPid === $record->pid || $record->expectedStop) {
                continue;
            }
            $this->stopChild(
                $record,
                WorkerState::STOPPING,
                false,
                ShutdownReason::FATAL_RUNTIME_ERROR,
            );
        }

        $this->emit(new SupervisorEvent(
            SupervisorEventType::RELOAD_FAILED,
            $this->now(),
            group: $failed->group->name,
            slot: $failed->slot,
            pid: $failed->pid,
            generation: $targetGeneration,
            exitReason: WorkerExitReason::RESTART_BUDGET_EXHAUSTED,
        ));
    }

    private function beginReload(): void
    {
        $slots = ChildSet::slots($this->groups, reloadableOnly: true);
        if ($slots === []) {
            $this->emit(new SupervisorEvent(SupervisorEventType::RELOAD_COMPLETED, $this->now(), generation: $this->generation));

            return;
        }

        $this->reloading = true;
        $this->reloadFailed = false;
        $this->generationReady = false;
        ++$this->generation;
        $this->reloadPending = array_map(
            static fn(array $slot): array => [$slot[0]->name, $slot[1]],
            $slots,
        );
        $this->reloadActive = [];
        $this->emit(new SupervisorEvent(
            SupervisorEventType::RELOAD_STARTED,
            $this->now(),
            generation: $this->generation,
        ));
        $this->pumpReload();
    }

    private function cancelRestartTimers(): void
    {
        foreach ($this->restartTimers as $timerId) {
            $this->loop->cancel($timerId);
        }

        $this->restartTimers = [];
    }

    private function checkGenerationReadiness(): void
    {
        if ($this->generationReady || !ChildSet::generationReady(
            $this->groups,
            $this->currentSlots,
            $this->children,
            $this->generation,
        )) {
            return;
        }

        $this->generationReady = true;
        $this->emit(new SupervisorEvent(
            SupervisorEventType::GENERATION_READY,
            $this->now(),
            generation: $this->generation,
        ));
    }

    private function checkReloadCompletion(): void
    {
        if (!$this->reloading) {
            return;
        }

        $this->pumpReload();
        if ($this->reloadPending !== [] || $this->reloadActive !== [] || !ChildSet::reloadComplete(
            $this->groups,
            $this->currentSlots,
            $this->children,
            $this->generation,
        )) {
            return;
        }

        $this->reloading = false;
        $this->checkGenerationReadiness();
        $this->emit(new SupervisorEvent(
            SupervisorEventType::RELOAD_COMPLETED,
            $this->now(),
            generation: $this->generation,
        ));

        if ($this->reloadQueued) {
            $this->reloadQueued = false;
            $this->beginReload();
        }
    }

    private function completeReloadSlot(string $group, int $slot, ?int $replacedPid = null): void
    {
        $key = $this->slotKey($group, $slot);
        if (!isset($this->reloadActive[$key])) {
            return;
        }

        $currentPid = $this->currentSlots[$group][$slot] ?? null;
        $current = $currentPid === null ? null : ($this->children[$currentPid] ?? null);
        if ($current === null || $current->generation !== $this->generation || !$current->state->serving()) {
            return;
        }

        if ($replacedPid !== null && $current->replacesPid === $replacedPid) {
            $current->replacesPid = null;
        }
        unset($this->reloadActive[$key]);
        $this->checkGenerationReadiness();
        $this->pumpReload();
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
        $this->cancelRestartTimers();

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

        if ($this->reloading && $record->group->reloadable) {
            $this->completeReloadSlot($record->group->name, $record->slot, $pid);
        }

        switch ($transition->action) {
            case ChildExitAction::CHECK_RELOAD:
                $this->checkReloadCompletion();

                break;
            case ChildExitAction::NONE:
                break;
            case ChildExitAction::RESTART:
                $this->scheduleRestart($record);

                break;
            case ChildExitAction::SPAWN_RECYCLE:
                $this->spawnWorker(
                    group: $record->group,
                    slot: $record->slot,
                    generation: $record->generation,
                    restartCount: $this->restartTracker->count($record->group->name, $record->slot),
                    replacesPid: null,
                    setCurrent: true,
                );

                break;
            case ChildExitAction::STOP_LOOP:
                $this->loop->stop();

                break;
        }
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
                $this->handleWorkerMessage($record, $message);
            }
        }

        if (feof($stream)) {
            ReadinessChannel::close($this->loop, $record);
        }
    }

    private function handleWorkerMessage(ChildRecord $record, string $message): void
    {
        if ($message === 'R') {
            if ($record->state !== WorkerState::STARTING) {
                return;
            }

            $record->state = WorkerState::READY;
            ReadinessChannel::ready($this->loop, $record);
            $this->emitWorker(SupervisorEventType::WORKER_READY, $record);

            if ($record->replacesPid !== null) {
                $replacedPid = $record->replacesPid;
                $this->currentSlots[$record->group->name][$record->slot] = $record->pid;
                $old = $this->children[$replacedPid] ?? null;
                if ($old !== null) {
                    $reason = $record->recycleReplacement
                        ? ShutdownReason::MANUAL_RECYCLE
                        : ShutdownReason::DEPLOYMENT_RELOAD;
                    $timeout = $record->recycleReplacement
                        ? $old->group->shutdownTimeoutSeconds
                        : $this->reloadPolicy->drainTimeoutSeconds;
                    $this->stopChild($old, WorkerState::DRAINING, false, $reason, $timeout);
                } elseif ($this->reloading) {
                    $record->replacesPid = null;
                    $this->completeReloadSlot($record->group->name, $record->slot);
                }
            } elseif (($this->currentSlots[$record->group->name][$record->slot] ?? null) === null) {
                $this->currentSlots[$record->group->name][$record->slot] = $record->pid;
                if ($this->reloading) {
                    $this->completeReloadSlot($record->group->name, $record->slot);
                }
            }

            $this->checkGenerationReadiness();
            $this->checkReloadCompletion();

            return;
        }

        if ($message === 'B' || $message === 'I') {
            if ($record->expectedStop || !$record->state->serving()) {
                return;
            }
            $record->state = $message === 'B' ? WorkerState::BUSY : WorkerState::IDLE;

            return;
        }

        if ($message === 'U') {
            if ($record->expectedStop) {
                return;
            }
            $record->state = WorkerState::UNHEALTHY;
            $this->emitWorker(SupervisorEventType::WORKER_UNHEALTHY, $record);

            return;
        }

        if (str_starts_with($message, 'X:')) {
            $reason = ShutdownReason::tryFrom(substr($message, 2));
            if ($reason !== null) {
                $record->shutdownReason = $reason;
            }
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

    private function pumpReload(): void
    {
        if (!$this->reloading) {
            return;
        }
        if (ChildSet::unavailableReloadableSlots(
            $this->groups,
            $this->currentSlots,
            $this->children,
        ) > $this->reloadPolicy->maxUnavailable) {
            return;
        }

        while ($this->reloadPending !== [] && count($this->reloadActive) < $this->reloadPolicy->maxSurge) {
            $next = array_shift($this->reloadPending);
            if ($next === null) {
                return;
            }
            [$groupName, $slot] = $next;
            $group = $this->groups[$groupName] ?? null;
            if ($group === null || !$group->reloadable) {
                continue;
            }

            $key = $this->slotKey($groupName, $slot);
            $this->reloadActive[$key] = true;
            if (isset($this->restartTimers[$key])) {
                $this->loop->cancel($this->restartTimers[$key]);
                unset($this->restartTimers[$key]);
            }

            $oldPid = $this->currentSlots[$groupName][$slot] ?? null;
            $this->spawnWorker(
                group: $group,
                slot: $slot,
                generation: $this->generation,
                restartCount: $this->restartTracker->count($groupName, $slot),
                replacesPid: $oldPid,
                setCurrent: $oldPid === null,
                readyTimeoutSeconds: $this->reloadPolicy->replacementReadyTimeoutSeconds,
            );
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

    /** @param resource $childReady */
    private function runChild(WorkerGroup $group, int $slot, int $generation, mixed $childReady): never
    {
        $this->normalizeChildProcess();
        WorkerChildRuntime::run($group, $slot, $generation, $childReady);
    }

    private function scheduleRestart(ChildRecord $record): void
    {
        $key = $this->slotKey($record->group->name, $record->slot);
        if (isset($this->restartTimers[$key])) {
            return;
        }

        $attempt = $this->restartTracker->nextAttempt($record->group, $record->slot);
        if ($attempt === null) {
            ++$this->restartReasonCounts[WorkerExitReason::RESTART_BUDGET_EXHAUSTED->value];
            $currentPid = $this->currentSlots[$record->group->name][$record->slot] ?? null;
            $current = $currentPid === null ? null : ($this->children[$currentPid] ?? null);
            if ($this->reloading
                && isset($this->reloadActive[$key])
                && $record->generation === $this->generation
                && $current !== null
                && $current->state->serving()) {
                $this->abortReload($record);

                return;
            }
            if ($record->recycleReplacement && $current !== null) {
                return;
            }

            $this->fail(new SupervisorException(sprintf(
                'Restart budget exhausted for worker group "%s".',
                $record->group->name,
            )));

            return;
        }

        $reason = $record->exitReason ?? WorkerExitReason::CRASH;
        ++$this->restartReasonCounts[$reason->value];
        $restartGeneration = $record->group->reloadable
            ? max($record->generation, $this->generation)
            : $record->generation;
        $this->emit(new SupervisorEvent(
            SupervisorEventType::WORKER_RESTART_SCHEDULED,
            $this->now(),
            group: $record->group->name,
            slot: $record->slot,
            generation: $restartGeneration,
            restartCount: $attempt->count,
            restartDelaySeconds: $attempt->delaySeconds,
            replacesPid: $record->replacesPid,
            exitReason: $reason,
        ));

        $this->restartTimers[$key] = $this->loop->delay(
            $attempt->delaySeconds,
            function () use ($record, $attempt, $key, $restartGeneration): void {
                unset($this->restartTimers[$key]);
                if ($this->stopping || ($this->reloadFailed && isset($this->reloadActive[$key]))) {
                    return;
                }

                $reloadReplacement = $this->reloading
                    && isset($this->reloadActive[$key])
                    && $restartGeneration === $this->generation;
                $this->spawnWorker(
                    group: $record->group,
                    slot: $record->slot,
                    generation: $restartGeneration,
                    restartCount: $attempt->count,
                    replacesPid: $record->replacesPid,
                    setCurrent: $record->replacesPid === null,
                    recycleReplacement: $record->recycleReplacement,
                    readyTimeoutSeconds: $reloadReplacement
                        ? $this->reloadPolicy->replacementReadyTimeoutSeconds
                        : null,
                );
            },
        );
    }

    private function slotKey(string $group, int $slot): string
    {
        return $group . ':' . $slot;
    }

    private function spawnInitialWorkers(): void
    {
        foreach (ChildSet::slots($this->groups) as [$group, $slot]) {
            $this->spawnWorker($group, $slot, $this->generation, 0, null, true);
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

            throw new SupervisorException(sprintf(
                'Unable to fork worker %s[%d].',
                $group->name,
                $slot,
            ));
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
        $signal = $force ? SIGKILL : SIGTERM;
        posix_kill($record->pid, $signal);

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

    private function replacementFor(int $pid): ?ChildRecord
    {
        foreach ($this->children as $record) {
            if ($record->replacesPid === $pid) {
                return $record;
            }
        }

        return null;
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
}
