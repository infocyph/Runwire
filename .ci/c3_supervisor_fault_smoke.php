<?php

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'Infocyph\\Runwire\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $file = dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

use Infocyph\Runwire\Exception\SupervisorException;
use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Supervisor\Enum\SupervisorEventType;
use Infocyph\Runwire\Supervisor\RestartPolicy;
use Infocyph\Runwire\Supervisor\Supervisor;
use Infocyph\Runwire\Supervisor\WorkerGroup;

if (!function_exists('pcntl_fork') || !function_exists('posix_kill')) {
    fwrite(STDERR, "Native process signals required.\n");
    exit(2);
}

try {
    $loop = new SelectLoop();
    $supervisor = new Supervisor($loop);
    $spawned = [];
    $supervisor->group(new WorkerGroup(
        name: 'fault-survival',
        count: 1,
        bootstrap: static function ($context): void {
            $context->ready();
            while (!$context->stopping()) {
                usleep(20_000);
            }
        },
        automaticReady: false,
        restartPolicy: new RestartPolicy(maxRestarts: 3, initialBackoffSeconds: 0.02),
    ));
    $supervisor->onEvent(static function ($event) use (&$spawned): void {
        if ($event->type === SupervisorEventType::WORKER_SPAWNED) {
            $spawned[] = $event->pid;
        }
    });
    $loop->delay(0.4, static function () use ($supervisor): void {
        $workers = $supervisor->status()->workers;
        if ($workers !== []) {
            posix_kill($workers[0]->pid, SIGKILL);
        }
    });
    $loop->delay(2.0, static fn() => $supervisor->stop());
    $supervisor->run();
    if (count($spawned) < 2 || count(array_unique($spawned)) < 2
        || $supervisor->status()->workerCount !== 0) {
        throw new RuntimeException('SIGKILL restart did not recover a distinct worker PID and drain cleanly.');
    }

    $failureLoop = new SelectLoop();
    $failureSupervisor = new Supervisor($failureLoop);
    $failureSpawns = 0;
    $failureSupervisor->group(new WorkerGroup(
        name: 'fault-exhaustion',
        count: 1,
        bootstrap: static function (): void {
            throw new RuntimeException('Injected startup crash.');
        },
        restartPolicy: new RestartPolicy(maxRestarts: 2, initialBackoffSeconds: 0.01, maxBackoffSeconds: 0.02),
        automaticReady: false,
        readyTimeoutSeconds: 1.0,
    ));
    $failureSupervisor->onEvent(static function ($event) use (&$failureSpawns): void {
        if ($event->type === SupervisorEventType::WORKER_SPAWNED) {
            ++$failureSpawns;
        }
    });
    $failureLoop->delay(4.0, static fn() => $failureSupervisor->stop());
    $failedAsExpected = false;
    try {
        $failureSupervisor->run();
    } catch (SupervisorException) {
        $failedAsExpected = true;
    }
    if (!$failedAsExpected || $failureSpawns !== 3 || $failureSupervisor->status()->workerCount !== 0) {
        throw new RuntimeException(sprintf(
            'Crash-loop restart budget invariant violated: raised=%d spawns=%d remaining=%d.',
            (int) $failedAsExpected,
            $failureSpawns,
            $failureSupervisor->status()->workerCount,
        ));
    }

    echo "C3 native SIGKILL recovery and bounded crash-loop smoke passed.\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
