<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Supervisor;

use Infocyph\Runwire\Exception\SupervisorException;
use Infocyph\Runwire\Loop\LoopInterface;
use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Supervisor\Internal\ChildRecord;
use Infocyph\Runwire\Supervisor\Internal\RestartTracker;
use Infocyph\Runwire\Supervisor\Internal\SignalBridge;
use LogicException;
use Throwable;

final class Supervisor
{
    private const int NANOS_PER_SECOND = 1_000_000_000;

    private LoopInterface $loop;

    /** @var array<string, WorkerGroup> */
    private array $groups = [];

    /** @var array<int, ChildRecord> */
    private array $children = [];

    /** @var array<string, array<int, int>> */
    private array $currentSlots = [];

    /** @var array<string, int> */
    private array $restartTimers = [];

    private bool $started = false;
    private bool $running = false;
    private bool $stopping = false;
    private bool $reloading = false;
    private bool $reloadQueued = false;
    private int $generation = 1;
    private ?SupervisorException $failure = null;

    private RestartTracker $restartTracker;
    private SignalBridge $signalBridge;

    public function __construct(?LoopInterface $loop = null)
    {
        $this->loop = $loop ?? new SelectLoop();
        $this->restartTracker = new RestartTracker();
        $this->signalBridge = new SignalBridge($this->loop);
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

        try {
            $this->signalBridge->open($this->processSignals(...));
            $this->spawnInitialWorkers();
            $this->loop->run();
        } catch (SupervisorException $exception) {
            $this->failure ??= $exception;
        } finally {
            $this->running = false;
            $this->forceCleanupChildren();
            $this->signalBridge->close();
        }

        if ($this->failure !== null) {
            throw $this->failure;
        }
    }

    public function stop(bool $force = false): void
    {
        if (!$this->running) {
            return;
        }

        if ($this->stopping) {
            if ($force) {
                foreach ($this->children as $record) {
                    $this->stopChild($record, WorkerState::STOPPING, true);
                }
            }

            return;
        }

        $this->stopping = true;
        $this->reloadQueued = false;
        $this->cancelRestartTimers();

        foreach ($this->children as $record) {
            $this->stopChild($record, WorkerState::STOPPING, $force);
        }

        if ($this->children === []) {
            $this->loop->stop();
        }
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

    public function status(): SupervisorStatus
    {
        $workers = [];

        foreach ($this->children as $record) {
            $workers[] = new WorkerStatus(
                group: $record->group->name,
                slot: $record->slot,
                pid: $record->pid,
                generation: $record->generation,
                state: $record->state,
                restartCount: $record->restartCount,
                startedAtMonotonic: $record->startedAtNs / self::NANOS_PER_SECOND,
            );
        }

        usort(
            $workers,
            static fn (WorkerStatus $left, WorkerStatus $right): int => [
                $left->group,
                $left->slot,
                $left->generation,
                $left->pid,
            ] <=> [
                $right->group,
                $right->slot,
                $right->generation,
                $right->pid,
            ],
        );

        return new SupervisorStatus(
            running: $this->running,
            stopping: $this->stopping,
            reloading: $this->reloading,
            generation: $this->generation,
            workers: $workers,
        );
    }

    private function spawnInitialWorkers(): void
    {
        foreach ($this->groups as $group) {
            for ($slot = 0; $slot < $group->count; ++$slot) {
                $this->spawnWorker($group, $slot, $this->generation, 0, null, true);
            }
        }
    }

    private function beginReload(): void
    {
        $this->reloading = true;
        ++$this->generation;

        foreach ($this->groups as $group) {
            for ($slot = 0; $slot < $group->count; ++$slot) {
                $key = $this->slotKey($group->name, $slot);
                if (isset($this->restartTimers[$key])) {
                    $this->loop->cancel($this->restartTimers[$key]);
                    unset($this->restartTimers[$key]);
                }

                $oldPid = $this->currentSlots[$group->name][$slot] ?? null;
                $restartCount = $this->restartTracker->count($group->name, $slot);

                $this->spawnWorker(
                    group: $group,
                    slot: $slot,
                    generation: $this->generation,
                    restartCount: $restartCount,
                    replacesPid: $oldPid,
                    setCurrent: $oldPid === null,
                );
            }
        }
    }

    private function spawnWorker(
        WorkerGroup $group,
        int $slot,
        int $generation,
        int $restartCount,
        ?int $replacesPid,
        bool $setCurrent,
    ): void {
        $pair = @stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($pair === false) {
            throw new SupervisorException('Unable to create worker readiness channel.');
        }

        [$parentReady, $childReady] = $pair;
        stream_set_blocking($parentReady, false);

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

        $readyTimerId = $this->loop->delay(
            $group->readyTimeoutSeconds,
            function () use ($pid): void {
                $record = $this->children[$pid] ?? null;
                if ($record === null || $record->state !== WorkerState::STARTING) {
                    return;
                }

                $record->state = WorkerState::FAILED;
                @posix_kill($pid, SIGKILL);
            },
        );

        $record = new ChildRecord(
            group: $group,
            slot: $slot,
            pid: $pid,
            generation: $generation,
            restartCount: $restartCount,
            startedAtNs: hrtime(true),
            readyStream: $parentReady,
            readyWatcherId: $readyWatcherId,
            readyTimerId: $readyTimerId,
            replacesPid: $replacesPid,
        );

        $this->children[$pid] = $record;

        if ($setCurrent) {
            $this->currentSlots[$group->name][$slot] = $pid;
        }
    }

    /**
     * @param resource $childReady
     */
    private function runChild(WorkerGroup $group, int $slot, int $generation, mixed $childReady): never
    {
        $this->normalizeChildProcess();

        $context = new WorkerContext(
            group: $group->name,
            slot: $slot,
            generation: $generation,
            pid: posix_getpid(),
            parentPid: posix_getppid(),
            readyStream: $childReady,
        );

        try {
            pcntl_async_signals(true);
            $stopHandler = static function () use ($context): void {
                $context->requestStop();
            };

            if (!pcntl_signal(SIGTERM, $stopHandler) || !pcntl_signal(SIGINT, $stopHandler)) {
                throw new SupervisorException('Unable to install worker stop signal handlers.');
            }

            if ($group->automaticReady) {
                $context->ready();
            }

            ($group->bootstrap)($context);
            exit(0);
        } catch (Throwable) {
            exit(70);
        }
    }

    private function normalizeChildProcess(): void
    {
        $this->signalBridge->normalizeChild();
        $this->closeInheritedReadyStreams();
    }

    private function closeInheritedReadyStreams(): void
    {
        foreach ($this->children as $record) {
            if (is_resource($record->readyStream)) {
                fclose($record->readyStream);
            }
        }
    }

    /**
     * @param resource $stream
     */
    private function handleReadyStream(int $pid, mixed $stream): void
    {
        $record = $this->children[$pid] ?? null;
        if ($record === null) {
            return;
        }

        $payload = @fread($stream, 16);
        if (is_string($payload) && str_contains($payload, 'R')) {
            $record->state = WorkerState::READY;
            $this->closeReadyChannel($record);

            if ($record->replacesPid !== null) {
                $this->currentSlots[$record->group->name][$record->slot] = $record->pid;

                $old = $this->children[$record->replacesPid] ?? null;
                if ($old !== null) {
                    $this->stopChild($old, WorkerState::DRAINING, false);
                }
            }

            $this->checkReloadCompletion();

            return;
        }

        if (feof($stream)) {
            $this->closeReadyChannel($record);
        }
    }

    private function closeReadyChannel(ChildRecord $record): void
    {
        if ($record->readyWatcherId > 0) {
            $this->loop->cancel($record->readyWatcherId);
            $record->readyWatcherId = 0;
        }

        if ($record->readyTimerId > 0) {
            $this->loop->cancel($record->readyTimerId);
            $record->readyTimerId = 0;
        }

        if (is_resource($record->readyStream)) {
            fclose($record->readyStream);
        }

        $record->readyStream = null;
    }

    private function stopChild(ChildRecord $record, WorkerState $state, bool $force): void
    {
        $record->expectedStop = true;
        $record->state = $state;
        $signal = $force ? SIGKILL : SIGTERM;
        @posix_kill($record->pid, $signal);

        if ($force || $record->killTimerId !== null) {
            return;
        }

        $record->killTimerId = $this->loop->delay(
            $record->group->shutdownTimeoutSeconds,
            function () use ($record): void {
                if (isset($this->children[$record->pid])) {
                    @posix_kill($record->pid, SIGKILL);
                }
            },
        );
    }

    /**
     * @param list<int> $signals
     */
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
        while (true) {
            $status = 0;
            $pid = pcntl_waitpid(-1, $status, WNOHANG);

            if ($pid > 0) {
                $this->handleChildExit($pid, $status);
                continue;
            }

            if ($pid === 0) {
                return;
            }

            $error = pcntl_get_last_error();
            if ($error === PCNTL_EINTR) {
                continue;
            }

            if ($error === PCNTL_ECHILD) {
                $this->reconcileNoChildren();

                return;
            }

            throw new SupervisorException(sprintf('waitpid failed with PCNTL error %d.', $error));
        }
    }

    private function handleChildExit(int $pid, int $status): void
    {
        $record = $this->children[$pid] ?? null;
        if ($record === null) {
            return;
        }

        $this->closeReadyChannel($record);

        if ($record->killTimerId !== null) {
            $this->loop->cancel($record->killTimerId);
            $record->killTimerId = null;
        }

        unset($this->children[$pid]);

        if (($this->currentSlots[$record->group->name][$record->slot] ?? null) === $pid) {
            unset($this->currentSlots[$record->group->name][$record->slot]);
        }

        if ($this->stopping) {
            if ($this->children === []) {
                $this->loop->stop();
            }

            return;
        }

        if ($record->expectedStop || $this->hasReplacementFor($pid)) {
            $this->checkReloadCompletion();

            return;
        }

        $this->scheduleRestart($record);
    }

    private function scheduleRestart(ChildRecord $record): void
    {
        $key = $this->slotKey($record->group->name, $record->slot);
        if (isset($this->restartTimers[$key])) {
            return;
        }

        $attempt = $this->restartTracker->nextAttempt($record->group, $record->slot);
        if ($attempt === null) {
            $this->fail(new SupervisorException(sprintf(
                'Restart budget exhausted for worker group "%s".',
                $record->group->name,
            )));

            return;
        }

        $this->restartTimers[$key] = $this->loop->delay(
            $attempt->delaySeconds,
            function () use ($record, $attempt, $key): void {
                unset($this->restartTimers[$key]);
                if ($this->stopping) {
                    return;
                }

                $this->spawnWorker(
                    group: $record->group,
                    slot: $record->slot,
                    generation: max($record->generation, $this->generation),
                    restartCount: $attempt->count,
                    replacesPid: $record->replacesPid,
                    setCurrent: $record->replacesPid === null,
                );
            },
        );
    }

    private function hasReplacementFor(int $pid): bool
    {
        foreach ($this->children as $record) {
            if ($record->replacesPid === $pid) {
                return true;
            }
        }

        return false;
    }

    private function checkReloadCompletion(): void
    {
        if (!$this->reloading) {
            return;
        }

        foreach ($this->groups as $group) {
            for ($slot = 0; $slot < $group->count; ++$slot) {
                $pid = $this->currentSlots[$group->name][$slot] ?? null;
                $record = $pid !== null ? ($this->children[$pid] ?? null) : null;

                if ($record === null
                    || $record->generation !== $this->generation
                    || $record->state !== WorkerState::READY) {
                    return;
                }
            }
        }

        foreach ($this->children as $record) {
            if ($record->generation < $this->generation) {
                return;
            }
        }

        $this->reloading = false;

        if ($this->reloadQueued) {
            $this->reloadQueued = false;
            $this->beginReload();
        }
    }

    private function cancelRestartTimers(): void
    {
        foreach ($this->restartTimers as $timerId) {
            $this->loop->cancel($timerId);
        }

        $this->restartTimers = [];
    }

    private function fail(SupervisorException $exception): void
    {
        $this->failure ??= $exception;
        $this->stop();
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
            $this->closeReadyChannel($record);
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

    private function forceCleanupChildren(): void
    {
        $this->cancelRestartTimers();

        foreach ($this->children as $record) {
            @posix_kill($record->pid, SIGKILL);
        }

        foreach (array_keys($this->children) as $pid) {
            do {
                $status = 0;
                $result = pcntl_waitpid($pid, $status);
            } while ($result === -1 && pcntl_get_last_error() === PCNTL_EINTR);
        }

        foreach ($this->children as $record) {
            $this->closeReadyChannel($record);
            if ($record->killTimerId !== null) {
                $this->loop->cancel($record->killTimerId);
            }
        }

        $this->children = [];
        $this->currentSlots = [];
    }

    private function slotKey(string $group, int $slot): string
    {
        return $group . ':' . $slot;
    }
}
