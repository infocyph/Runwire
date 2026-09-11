<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Supervisor\Internal;

use Closure;
use Infocyph\Runwire\Supervisor\SupervisorEvent;
use Throwable;

final class LifecycleEmitter
{
    private int $listenerFailures = 0;

    /** @var list<Closure(SupervisorEvent): void> */
    private array $listeners = [];

    public function emit(SupervisorEvent $event): void
    {
        foreach ($this->listeners as $listener) {
            try {
                $listener($event);
            } catch (Throwable) {
                ++$this->listenerFailures;
            }
        }
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
