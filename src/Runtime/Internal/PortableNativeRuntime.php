<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Runtime\Internal;

use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Runtime\RuntimeSelection;
use Infocyph\Runwire\RuntimeContext;
use Infocyph\Runwire\RuntimeOptions;
use Infocyph\Runwire\Supervisor\Enum\WorkerRole;
use Infocyph\Runwire\Supervisor\WorkerContext;
use Infocyph\Runwire\Supervisor\WorkerRecyclePolicy;
use LogicException;

/**
 * Runs native listeners in one process when prefork process capabilities are unavailable.
 */
final class PortableNativeRuntime
{
    /** @var list<WorkerContext> */
    private array $contexts = [];

    private ?int $drainDeadlineTimer = null;

    private ?int $drainPollTimer = null;

    /** @var list<NativeWorkerHandle> */
    private array $handles = [];

    private ?SelectLoop $loop = null;

    private bool $stopping = false;

    /**
     * @param array<string, BoundServer|BoundStreamServer|BoundDatagramServer> $bound
     */
    public function __construct(
        private readonly array $bound,
        private readonly RuntimeSelection $selection,
        private readonly RuntimeOptions $options,
    ) {}

    /**
     * Run all bound native listeners on one shared SelectLoop.
     */
    public function run(): void
    {
        if ($this->loop !== null) {
            throw new LogicException('Portable native runtime can only be run once.');
        }

        $loop = new SelectLoop($this->options->diagnostics->callbackOverrunSeconds);
        $this->loop = $loop;

        try {
            foreach ($this->bound as $name => $target) {
                $this->attachTarget($name, $target);
            }
            $loop->run();
        } finally {
            $this->close();
        }
    }

    /**
     * Request graceful or forced shutdown of the portable runtime.
     */
    public function stop(bool $force = false): void
    {
        $loop = $this->loop;
        if ($loop === null) {
            return;
        }

        if ($force) {
            $this->forceStop($loop);

            return;
        }

        $this->stopGracefully($loop);
    }

    private static function groupName(
        string $name,
        BoundServer|BoundStreamServer|BoundDatagramServer $target,
    ): string {
        return match (true) {
            $target instanceof BoundServer => 'http:' . $name,
            $target instanceof BoundStreamServer => $target->definition->transport->value . ':' . $name,
            $target instanceof BoundDatagramServer => 'udp:' . $name,
        };
    }

    private function allDrained(): bool
    {
        return array_all($this->handles, fn(NativeWorkerHandle $handle): bool => $handle->drained());
    }

    private function attachHttp3(string $name, BoundServer $target): void
    {
        if ($target->definition->http3 === null || !$this->selection->capabilities->supportsQuic) {
            return;
        }

        $context = $this->newContext('http3:' . $name, WorkerRole::HTTP);
        $runtimeContext = $this->runtimeContext($context);
        $this->handles[] = NativeHttp3Worker::attach(
            $this->requiredLoop(),
            $context,
            $target->definition,
            $target->listener->address(),
            $runtimeContext,
            $this->options->requestExecution,
            $this->options->applicationLifecycle,
            $this->options->diagnostics,
        );
    }

    private function attachTarget(
        string $name,
        BoundServer|BoundStreamServer|BoundDatagramServer $target,
    ): void {
        $role = $target instanceof BoundServer ? WorkerRole::HTTP : WorkerRole::CUSTOM;
        $context = $this->newContext(self::groupName($name, $target), $role);
        $loop = $this->requiredLoop();

        $this->handles[] = match (true) {
            $target instanceof BoundServer => NativeHttpWorker::attach(
                $loop,
                $context,
                $target,
                $this->runtimeContext($context),
                $this->options->requestExecution,
                $this->options->applicationLifecycle,
                $this->options->diagnostics,
            ),
            $target instanceof BoundStreamServer => NativeStreamWorker::attach($loop, $context, $target),
            $target instanceof BoundDatagramServer => NativeDatagramWorker::attach($loop, $context, $target),
        };

        if ($target instanceof BoundServer) {
            $this->attachHttp3($name, $target);
        }
    }

    private function cancelDrainTimers(): void
    {
        $loop = $this->loop;
        if ($loop === null) {
            return;
        }
        if ($this->drainPollTimer !== null) {
            $loop->cancel($this->drainPollTimer);
            $this->drainPollTimer = null;
        }
        if ($this->drainDeadlineTimer !== null) {
            $loop->cancel($this->drainDeadlineTimer);
            $this->drainDeadlineTimer = null;
        }
    }

    private function close(): void
    {
        $this->cancelDrainTimers();
        foreach (array_reverse($this->handles) as $handle) {
            $handle->close();
        }
        foreach (array_reverse($this->contexts) as $context) {
            $context->close();
        }

        $this->handles = [];
        $this->contexts = [];
        $this->loop = null;
    }

    private function drainTimeoutSeconds(): float
    {
        $timeout = $this->options->workerRecycle->gracefulTimeoutSeconds;
        foreach ($this->bound as $target) {
            $timeout = max($timeout, $target->definition->workerShutdownTimeoutSeconds);
        }

        return $timeout;
    }

    private function forceStop(SelectLoop $loop): void
    {
        $this->stopping = true;
        foreach ($this->handles as $handle) {
            $handle->stop(true);
        }
        $loop->stop();
    }

    private function newContext(string $group, WorkerRole $role): WorkerContext
    {
        $pid = getmypid();
        $pid = is_int($pid) ? $pid : 0;
        $context = new WorkerContext(
            group: $group,
            slot: 0,
            generation: 0,
            pid: $pid,
            parentPid: $pid,
            recyclePolicy: new WorkerRecyclePolicy(
                gracefulTimeoutSeconds: $this->options->workerRecycle->gracefulTimeoutSeconds,
            ),
            admissionPolicy: $this->options->admission,
            role: $role,
        );
        $this->contexts[] = $context;

        return $context;
    }

    private function requiredLoop(): SelectLoop
    {
        return $this->loop ?? throw new LogicException('Portable native loop is unavailable before run().');
    }

    private function runtimeContext(WorkerContext $context): RuntimeContext
    {
        return RuntimeContext::fromCapabilities(
            $this->selection->capabilities,
            'native-portable',
            workerSlot: 0,
            generation: 0,
            pid: $context->pid,
            concurrent: false,
        );
    }

    private function scheduleDrainCompletion(SelectLoop $loop): void
    {
        $this->drainPollTimer = $loop->repeat(0.01, function () use ($loop): void {
            if (!$this->allDrained()) {
                return;
            }

            $this->cancelDrainTimers();
            $loop->stop();
        });
        $this->drainDeadlineTimer = $loop->delay($this->drainTimeoutSeconds(), function () use ($loop): void {
            foreach ($this->handles as $handle) {
                if (!$handle->drained()) {
                    $handle->stop(true);
                }
            }
            $this->cancelDrainTimers();
            $loop->stop();
        });
    }

    private function stopGracefully(SelectLoop $loop): void
    {
        if ($this->stopping) {
            return;
        }

        $this->stopping = true;
        foreach ($this->handles as $handle) {
            $handle->stop();
        }
        if ($this->allDrained()) {
            $loop->stop();

            return;
        }

        $this->scheduleDrainCompletion($loop);
    }
}
