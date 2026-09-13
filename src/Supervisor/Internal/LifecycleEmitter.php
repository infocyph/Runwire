<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Supervisor\Internal;

use Closure;
use Infocyph\Runwire\Supervisor\Enum\SupervisorEventType;
use Infocyph\Runwire\Supervisor\SupervisorEvent;
use Throwable;

final class LifecycleEmitter
{
    /** @var array<string, int> */
    private array $failureCounts;

    private int $listenerFailures = 0;

    /** @var list<Closure(SupervisorEvent): void> */
    private array $listeners = [];

    public function __construct()
    {
        $this->failureCounts = array_fill_keys(
            array_map(static fn(SupervisorEventType $type): string => $type->value, SupervisorEventType::cases()),
            0,
        );
    }

    public function emit(SupervisorEvent $event): void
    {
        foreach ($this->listeners as $listener) {
            try {
                $listener($event);
            } catch (Throwable) {
                ++$this->listenerFailures;
                ++$this->failureCounts[$event->type->value];
            }
        }
    }

    /** @return array<string, int> */
    public function failureCounts(): array
    {
        return $this->failureCounts;
    }

    /** @param callable(SupervisorEvent): void $listener */
    public function listen(callable $listener): void
    {
        $this->listeners[] = Closure::fromCallable($listener);
    }

    public function listenerFailures(): int
    {
        return $this->listenerFailures;
    }
}
