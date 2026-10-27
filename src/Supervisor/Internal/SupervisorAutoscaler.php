<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Supervisor\Internal;

use Closure;
use Infocyph\Runwire\Loop\LoopInterface;
use Infocyph\Runwire\Supervisor\AutoscalePolicy;
use Infocyph\Runwire\Supervisor\WorkerGroup;
use Throwable;

/**
 * Owns native supervisor autoscale observation windows, timers and slot changes.
 *
 * Supervisor callbacks retain sole ownership of actual fork/restart/stop process
 * operations; this coordinator never creates workers outside the owning master.
 */
final class SupervisorAutoscaler
{
    /** @var array<string, array{controller:AutoscaleWindowController,policy:AutoscalePolicy,sample:Closure}> */
    private array $configurations = [];

    /** @var array<string, int> */
    private array $timers = [];

    /** @var Closure():bool */
    private readonly Closure $isBlocked;

    /** @var Closure(string):WorkerGroup */
    private readonly Closure $getGroup;

    /** @var Closure(WorkerGroup):void */
    private readonly Closure $putGroup;

    /** @var Closure(string,int):bool */
    private readonly Closure $slotOccupied;

    /** @var Closure(WorkerGroup,int):void */
    private readonly Closure $spawn;

    /** @var Closure(string,int):void */
    private readonly Closure $retire;

    /** @var Closure():float */
    private readonly Closure $now;

    /**
     * @param callable():bool $isBlocked
     * @param callable(string):WorkerGroup $getGroup
     * @param callable(WorkerGroup):void $putGroup
     * @param callable(string,int):bool $slotOccupied
     * @param callable(WorkerGroup,int):void $spawn
     * @param callable(string,int):void $retire
     * @param callable():float $now
     */
    public function __construct(
        private readonly LoopInterface $loop,
        callable $isBlocked,
        callable $getGroup,
        callable $putGroup,
        callable $slotOccupied,
        callable $spawn,
        callable $retire,
        callable $now,
    ) {
        $this->isBlocked = Closure::fromCallable($isBlocked);
        $this->getGroup = Closure::fromCallable($getGroup);
        $this->putGroup = Closure::fromCallable($putGroup);
        $this->slotOccupied = Closure::fromCallable($slotOccupied);
        $this->spawn = Closure::fromCallable($spawn);
        $this->retire = Closure::fromCallable($retire);
        $this->now = Closure::fromCallable($now);
    }

    /** @param callable():array{cpuRatio:float,queued:int,successfulRps:float,p99Milliseconds:float} $sample */
    public function configure(string $name, AutoscalePolicy $policy, callable $sample): void
    {
        $this->configurations[$name] = [
            'controller' => new AutoscaleWindowController($policy),
            'policy' => $policy,
            'sample' => Closure::fromCallable($sample),
        ];
    }

    /** Register bounded recurring native worker scale observations. */
    public function start(): void
    {
        foreach ($this->configurations as $name => $configuration) {
            $this->timers[$name] = $this->loop->repeat(
                $configuration['policy']->sampleSeconds,
                function () use ($name): void {
                    $this->sample($name);
                },
            );
        }
    }

    /** Cancel all native autoscale observations during shutdown or restart. */
    public function stop(): void
    {
        foreach ($this->timers as $timer) {
            $this->loop->cancel($timer);
        }
        $this->timers = [];
    }

    private function sample(string $name): void
    {
        if (($this->isBlocked)()) {
            return;
        }
        $configuration = $this->configurations[$name];
        $group = ($this->getGroup)($name);
        $target = $this->observe($configuration, $group->count);
        if ($target === null || $target === $group->count) {
            return;
        }

        $this->resize($group, $target);
    }

    /**
     * @param array{controller:AutoscaleWindowController,policy:AutoscalePolicy,sample:Closure} $configuration
     */
    private function observe(array $configuration, int $current): ?int
    {
        try {
            $metrics = ($configuration['sample'])();
            if (!is_array($metrics)
                || !is_float($metrics['cpuRatio'] ?? null)
                || !is_int($metrics['queued'] ?? null)
                || !is_float($metrics['successfulRps'] ?? null)
                || !is_float($metrics['p99Milliseconds'] ?? null)) {
                $configuration['controller']->invalidateSample();

                return null;
            }

            return $configuration['controller']->observe(
                currentWorkers: $current,
                cpuRatio: $metrics['cpuRatio'],
                queued: $metrics['queued'],
                successfulRps: $metrics['successfulRps'],
                p99Milliseconds: $metrics['p99Milliseconds'],
                observedAtSeconds: ($this->now)(),
            );
        } catch (Throwable) {
            // Missing or invalid measurements must never grant fork/kill authority.
            $configuration['controller']->invalidateSample();

            return null;
        }
    }

    private function resize(WorkerGroup $group, int $target): void
    {
        $old = $group->count;
        $next = $group->withCount($target);
        ($this->putGroup)($next);
        if ($target > $old) {
            for ($slot = $old; $slot < $target; ++$slot) {
                if (($this->slotOccupied)($group->name, $slot)) {
                    continue;
                }
                ($this->spawn)($next, $slot);
            }

            return;
        }

        for ($slot = $target; $slot < $old; ++$slot) {
            ($this->retire)($group->name, $slot);
        }
    }
}
