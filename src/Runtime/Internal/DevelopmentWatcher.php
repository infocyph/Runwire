<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Runtime\Internal;

use Closure;
use Infocyph\Runwire\Loop\LoopInterface;
use Infocyph\Runwire\Runtime\DevelopmentWatchPolicy;
use Throwable;

final class DevelopmentWatcher
{
    /** @var Closure(): void */
    private readonly Closure $onChange;

    /** @var Closure(): string */
    private readonly Closure $snapshotter;

    private ?int $debounceTimer = null;

    private int $failures = 0;

    private ?int $pollTimer = null;

    private bool $running = false;

    private ?string $snapshot = null;

    /**
     * @param callable(): void $onChange
     * @param callable(): string|null $snapshotter
     */
    public function __construct(
        private readonly LoopInterface $loop,
        private readonly DevelopmentWatchPolicy $policy,
        callable $onChange,
        ?callable $snapshotter = null,
    ) {
        $this->onChange = Closure::fromCallable($onChange);
        $scanner = new DevelopmentFileScanner($this->policy);
        $this->snapshotter = $snapshotter === null ? $scanner->snapshot(...) : Closure::fromCallable($snapshotter);
    }

    public function failureCount(): int
    {
        return $this->failures;
    }

    public function running(): bool
    {
        return $this->running;
    }

    public function start(): void
    {
        if ($this->running || !$this->policy->enabled) {
            return;
        }

        $this->running = true;
        $this->poll();
        $this->pollTimer = $this->loop->repeat(
            $this->policy->pollIntervalSeconds,
            $this->pollTick(...),
        );
    }

    public function stop(): void
    {
        if (!$this->running) {
            return;
        }

        $this->running = false;
        if ($this->pollTimer !== null) {
            $this->loop->cancel($this->pollTimer);
            $this->pollTimer = null;
        }
        if ($this->debounceTimer !== null) {
            $this->loop->cancel($this->debounceTimer);
            $this->debounceTimer = null;
        }
    }

    private function poll(): void
    {
        if (!$this->running) {
            return;
        }

        try {
            $snapshot = ($this->snapshotter)();
        } catch (Throwable) {
            ++$this->failures;

            return;
        }

        if ($this->snapshot === null) {
            $this->snapshot = $snapshot;

            return;
        }
        if (hash_equals($this->snapshot, $snapshot)) {
            return;
        }

        $this->snapshot = $snapshot;
        if ($this->debounceTimer !== null) {
            $this->loop->cancel($this->debounceTimer);
        }
        $this->debounceTimer = $this->loop->delay(
            $this->policy->debounceSeconds,
            $this->triggerTick(...),
        );
    }

    private function pollTick(int $timer): void
    {
        if ($this->pollTimer === $timer) {
            $this->poll();
        }
    }

    private function trigger(): void
    {
        $this->debounceTimer = null;
        if (!$this->running) {
            return;
        }

        try {
            ($this->onChange)();
        } catch (Throwable) {
            ++$this->failures;
        }
    }

    private function triggerTick(int $timer): void
    {
        if ($this->debounceTimer === $timer) {
            $this->trigger();
        }
    }
}
