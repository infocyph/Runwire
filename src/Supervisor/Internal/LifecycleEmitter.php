<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Supervisor\Internal;

use Closure;
use Infocyph\Runwire\Supervisor\Enum\SupervisorEventType;
use Infocyph\Runwire\Supervisor\SupervisorEvent;
use Throwable;

/**
 * Delivers supervisor lifecycle events while isolating and counting listener failures.
 */
final class LifecycleEmitter
{
    /** @var array<string, int> */
    private array $failureCounts;

    private int $listenerFailures = 0;

    /** @var list<Closure(SupervisorEvent): void> */
    private array $listeners = [];

    /**
     * Initialize per-event listener failure counters.
     */
    public function __construct()
    {
        $this->failureCounts = array_fill_keys(
            array_map(static fn(SupervisorEventType $type): string => $type->value, SupervisorEventType::cases()),
            0,
        );
    }

    /**
     * Emit one supervisor event to all registered listeners.
     */
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

    /**
     * Return listener failure counts grouped by event type.
     *
     * @return array<string, int>
     */
    public function failureCounts(): array
    {
        return $this->failureCounts;
    }

    /**
     * Register a supervisor lifecycle listener.
     *
     * @param callable(SupervisorEvent): void $listener
     */
    public function listen(callable $listener): void
    {
        $this->listeners[] = Closure::fromCallable($listener);
    }

    /**
     * Return the cumulative number of lifecycle listener failures.
     */
    public function listenerFailures(): int
    {
        return $this->listenerFailures;
    }
}
