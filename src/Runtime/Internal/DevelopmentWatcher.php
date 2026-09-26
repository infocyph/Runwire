<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Runtime\Internal;

use Closure;
use FilesystemIterator;
use Infocyph\Runwire\Loop\LoopInterface;
use Infocyph\Runwire\Runtime\DevelopmentWatchPolicy;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Throwable;

/**
 * Polls development files and debounces change callbacks on an event loop.
 */
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
        $this->snapshotter = $snapshotter === null ? $this->snapshot(...) : Closure::fromCallable($snapshotter);
    }

    /**
     * Returns the number of snapshot or callback failures observed.
     */
    public function failureCount(): int
    {
        return $this->failures;
    }

    /**
     * Reports whether development watching is active.
     */
    public function running(): bool
    {
        return $this->running;
    }

    /**
     * Starts polling watched paths when the policy is enabled.
     */
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

    /**
     * Stops polling and cancels any pending debounce callback.
     */
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

    /** @param list<string> $entries */
    private function appendSnapshotEntry(SplFileInfo $file, array &$entries): void
    {
        if (count($entries) >= $this->policy->maxFiles) {
            throw new RuntimeException(sprintf(
                'Development watcher file limit of %d was exceeded.',
                $this->policy->maxFiles,
            ));
        }

        $entries[] = sprintf(
            '%s:%d:%d',
            $file->getPathname(),
            $file->getMTime(),
            $file->getSize(),
        );
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

    private function snapshot(): string
    {
        $entries = [];
        foreach ($this->policy->paths as $path) {
            if (is_file($path)) {
                $this->appendSnapshotEntry(new SplFileInfo($path), $entries);

                continue;
            }
            if (!is_dir($path)) {
                $entries[] = 'missing:' . $path;

                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY,
            );
            foreach ($iterator as $file) {
                if (!$file instanceof SplFileInfo || !$file->isFile()) {
                    continue;
                }
                $this->appendSnapshotEntry($file, $entries);
            }
        }

        sort($entries, SORT_STRING);

        return hash('sha256', implode("\n", $entries));
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
