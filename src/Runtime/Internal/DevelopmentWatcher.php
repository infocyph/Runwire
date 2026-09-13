<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Runtime\Internal;

use Closure;
use Infocyph\Runwire\Loop\LoopInterface;
use Infocyph\Runwire\Runtime\DevelopmentWatchPolicy;
use Throwable;

final class DevelopmentWatcher
{
    private readonly LoopInterface $loop;

    /** @var Closure(): void */
    private readonly Closure $onChange;

    private readonly DevelopmentWatchPolicy $policy;

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
        LoopInterface $loop,
        DevelopmentWatchPolicy $policy,
        callable $onChange,
        ?callable $snapshotter = null,
    ) {
        $this->loop = $loop;
        $this->onChange = Closure::fromCallable($onChange);
        $this->policy = $policy;
        $scanner = new DevelopmentFileScanner($policy);
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
            fn(int $timer): void => $this->poll(),
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
            fn(int $timer): void => $this->trigger(),
        );
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
}
