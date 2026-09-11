<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Supervisor\Internal;

use Closure;
use Infocyph\Runwire\Supervisor\SupervisorEvent;
use Throwable;

final class LifecycleEmitter
{
    /** @var list<Closure(SupervisorEvent): void> */
    private array $listeners = [];
    private int $listenerFailures = 0;

    /** @param callable(SupervisorEvent): void $listener */
    public function listen(callable $listener): void
    {
        $this->listeners[] = Closure::fromCallable($listener);
    }

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

    public function listenerFailures(): int
    {
        return $this->listenerFailures;
    }
}
