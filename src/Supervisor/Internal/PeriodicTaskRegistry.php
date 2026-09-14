<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Supervisor\Internal;

use Closure;
use Infocyph\Runwire\Loop\LoopInterface;
use Infocyph\Runwire\Supervisor\PeriodicTaskHandle;
use InvalidArgumentException;
use LogicException;

/**
 * Registers bounded periodic worker tasks and coordinates them with an event loop.
 */
final class PeriodicTaskRegistry
{
    private const float MAX_INTERVAL_SECONDS = 86_400.0;

    private const int MAX_TASKS = 128;

    private const float MIN_INTERVAL_SECONDS = 0.001;

    private bool $draining = false;

    private ?LoopInterface $loop = null;

    /** @var array<string, array{interval: float, callback: Closure, timer: ?int}> */
    private array $tasks = [];

    /**
     * Attach the event loop used to schedule registered tasks.
     */
    public function attach(LoopInterface $loop): void
    {
        if ($this->loop !== null && $this->loop !== $loop && $this->tasks !== []) {
            throw new LogicException('Worker periodic tasks cannot switch event loops after registration.');
        }
        if ($this->loop === $loop) {
            return;
        }

        $this->loop = $loop;
        foreach (array_keys($this->tasks) as $name) {
            $this->schedule($name);
        }
    }

    /**
     * Cancel and remove a named periodic task.
     */
    public function cancel(string $name): bool
    {
        $task = $this->tasks[$name] ?? null;
        if ($task === null) {
            return false;
        }

        if ($task['timer'] !== null && $this->loop !== null) {
            $this->loop->cancel($task['timer']);
        }
        unset($this->tasks[$name]);

        return true;
    }

    /**
     * Drain active timers and release all registered tasks and loop state.
     */
    public function close(): void
    {
        $this->drain();
        $this->tasks = [];
        $this->loop = null;
    }

    /**
     * Stop scheduling periodic tasks while preserving their registrations.
     */
    public function drain(): void
    {
        if ($this->draining) {
            return;
        }

        $this->draining = true;
        if ($this->loop === null) {
            return;
        }

        foreach ($this->tasks as $name => $task) {
            if ($task['timer'] === null) {
                continue;
            }

            $this->loop->cancel($task['timer']);
            $this->tasks[$name]['timer'] = null;
        }
    }

    /**
     * Register a named periodic callback and return its cancellation handle.
     *
     * @param callable(): void $callback
     */
    public function register(string $name, float $intervalSeconds, callable $callback): PeriodicTaskHandle
    {
        self::validateName($name);
        self::validateInterval($intervalSeconds);
        if ($this->draining) {
            throw new LogicException('Worker is draining and cannot schedule new periodic work.');
        }
        if (isset($this->tasks[$name])) {
            throw new LogicException(sprintf('Periodic task "%s" is already registered for this worker.', $name));
        }
        if (count($this->tasks) >= self::MAX_TASKS) {
            throw new LogicException(sprintf('Worker periodic task limit of %d is exhausted.', self::MAX_TASKS));
        }

        $this->tasks[$name] = [
            'interval' => $intervalSeconds,
            'callback' => Closure::fromCallable($callback),
            'timer' => null,
        ];
        $this->schedule($name);

        return new PeriodicTaskHandle(fn(): bool => $this->cancel($name));
    }

    private static function validateInterval(float $seconds): void
    {
        if (
            !is_finite($seconds)
            || $seconds < self::MIN_INTERVAL_SECONDS
            || $seconds > self::MAX_INTERVAL_SECONDS
        ) {
            throw new InvalidArgumentException('Periodic task interval must be finite and between 0.001 and 86400 seconds.');
        }
    }

    private static function validateName(string $name): void
    {
        if ($name === '' || strlen($name) > 64 || preg_match('/^[A-Za-z0-9._:-]+$/D', $name) !== 1) {
            throw new InvalidArgumentException('Periodic task name must be a 1-64 character safe identifier.');
        }
    }

    private function run(string $name, int $timer): void
    {
        $task = $this->tasks[$name] ?? null;
        if ($task === null || $task['timer'] !== $timer) {
            return;
        }

        $this->tasks[$name]['timer'] = null;
        if ($this->draining) {
            return;
        }

        ($task['callback'])();
        $this->schedule($name);
    }

    private function schedule(string $name): void
    {
        $task = $this->tasks[$name] ?? null;
        if ($task === null || $task['timer'] !== null || $this->draining || $this->loop === null) {
            return;
        }

        $this->tasks[$name]['timer'] = $this->loop->delay(
            $task['interval'],
            function (int $timer) use ($name): void {
                $this->run($name, $timer);
            },
        );
    }
}
