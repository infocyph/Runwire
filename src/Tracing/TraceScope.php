<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Tracing;

use Closure;
use Fiber;
use LogicException;
use WeakMap;

/**
 * Owner-scoped trace propagation across explicitly wrapped Fiber boundaries.
 * This object is per worker/request owner: no static global trace identity.
 */
final class TraceScope
{
    /** @var WeakMap<Fiber,TraceContext> */
    private WeakMap $fibers;

    private ?TraceContext $root = null;

    private readonly int $pid;

    public function __construct()
    {
        $this->fibers = new WeakMap();
        $pid = getmypid();
        if (!is_int($pid)) {
            throw new LogicException('Trace scope requires a process owner.');
        }
        $this->pid = $pid;
    }

    public function current(): ?TraceContext
    {
        $this->assertOwner();
        $fiber = Fiber::getCurrent();

        return $fiber === null ? $this->root : ($this->fibers[$fiber] ?? null);
    }

    /** Restore the previous scope even when a callback fails or a Fiber suspends. */
    public function run(TraceContext $context, callable $callback): mixed
    {
        $this->assertOwner();
        $fiber = Fiber::getCurrent();
        $previous = $this->current();
        $this->set($fiber, $context);

        try {
            return $callback();
        } finally {
            $this->set($fiber, $previous);
        }
    }

    /** Explicitly capture parent context for deferred callbacks and child Fibers. */
    public function wrap(callable $callback): Closure
    {
        $this->assertOwner();
        $context = $this->current();
        $closure = Closure::fromCallable($callback);
        if ($context === null) {
            return $closure;
        }

        return fn(mixed ...$arguments): mixed => $this->run(
            $context,
            static fn(): mixed => $closure(...$arguments),
        );
    }

    private function set(?Fiber $fiber, ?TraceContext $context): void
    {
        if ($fiber === null) {
            $this->root = $context;

            return;
        }
        if ($context === null) {
            unset($this->fibers[$fiber]);

            return;
        }
        $this->fibers[$fiber] = $context;
    }

    private function assertOwner(): void
    {
        if (getmypid() !== $this->pid) {
            throw new LogicException('A tracing scope must be recreated after fork.');
        }
    }
}
