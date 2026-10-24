<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Network\Internal;

use Closure;
use Infocyph\Runwire\Network\Enum\CloseReason;
use Infocyph\Runwire\Network\Enum\ConnectionState;
use OverflowException;

/** @internal Tracks removable close observers without growing a pooled connection's callbacks. */
trait ConnectionCloseObserverTrait
{
    /** @var array<int, Closure> */
    private array $closeCallbacks = [];

    private int $nextCloseCallbackId = 1;

    /** @param callable(self, CloseReason): void $callback */
    public function onClose(callable $callback): self
    {
        $this->registerCloseObserver($callback);

        return $this;
    }

    /**
     * Register a close observer with a removable handle.
     * The callback executes immediately for an already closed connection.
     */
    public function registerCloseObserver(callable $callback): ?int
    {
        $closure = Closure::fromCallable($callback);
        if ($this->state === ConnectionState::CLOSED && $this->closeReason !== null) {
            $closure($this, $this->closeReason);

            return null;
        }
        if ($this->nextCloseCallbackId === PHP_INT_MAX) {
            throw new OverflowException('Connection close observer handles exhausted.');
        }

        $id = $this->nextCloseCallbackId++;
        $this->closeCallbacks[$id] = $closure;

        return $id;
    }

    /** Remove an active close observer while preserving the other observers. */
    public function unregisterCloseObserver(int $id): bool
    {
        if (!isset($this->closeCallbacks[$id])) {
            return false;
        }

        unset($this->closeCallbacks[$id]);

        return true;
    }
}
