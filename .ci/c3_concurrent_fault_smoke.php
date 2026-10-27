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

use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Supervisor\AutoscalePolicy;
use Infocyph\Runwire\Supervisor\Enum\SupervisorEventType;
use Infocyph\Runwire\Supervisor\Supervisor;
use Infocyph\Runwire\Supervisor\WorkerGroup;

if (!function_exists('pcntl_fork') || !function_exists('posix_kill')) {
    throw new RuntimeException('Native process signals are required for C3 concurrent lifecycle QA.');
}

$loop = new SelectLoop();
$supervisor = new Supervisor($loop);
$supervisor->group(new WorkerGroup(
    name: 'c3-concurrent-fault',
    count: 2,
    bootstrap: static function ($context): void {
        $context->ready();
        while (!$context->stopping()) {
            usleep(20_000);
        }
    },
    automaticReady: false,
));

$samples = 0;
$supervisor->autoscale(
    'c3-concurrent-fault',
    new AutoscalePolicy(minWorkers: 1, maxWorkers: 2, scaleOutQueueDepth: 1, sampleSeconds: 1.0, cooldownSeconds: 1.0),
    static function () use (&$samples): array {
        $busy = $samples >= 4 && $samples < 10;
        ++$samples;

        return [
            'cpuRatio' => $busy ? 0.90 : 0.05,
            'queued' => $busy ? 6 : 0,
            'successfulRps' => $busy ? 60.0 : 0.0,
            'p99Milliseconds' => $busy ? 300.0 : 1.0,
        ];
    },
);

$spawned = [];
$reloads = 0;
$reloadFailed = false;
$restarts = 0;
$killedPid = null;
$invalidOwnership = false;
$counts = [];
$supervisor->onEvent(static function ($event) use (&$spawned, &$reloads, &$reloadFailed, &$restarts): void {
    match ($event->type) {
        SupervisorEventType::WORKER_SPAWNED => $spawned[] = $event->pid,
        SupervisorEventType::RELOAD_COMPLETED => ++$reloads,
        SupervisorEventType::RELOAD_FAILED => $reloadFailed = true,
        SupervisorEventType::WORKER_RESTART_SCHEDULED => ++$restarts,
        default => null,
    };
});

$observer = $loop->repeat(0.15, static function () use ($supervisor, &$counts, &$invalidOwnership): void {
    $status = $supervisor->status();
    $counts[] = $status->currentWorkerCount;
    $owners = [];
    foreach ($status->workers as $worker) {
        if (!$worker->current) {
            continue;
        }
        if (isset($owners[$worker->slot])) {
            $invalidOwnership = true;
        }
        $owners[$worker->slot] = $worker->pid;
    }
});

$loop->delay(3.8, static function () use ($supervisor, &$killedPid): void {
    foreach ($supervisor->status()->workers as $worker) {
        if ($worker->current) {
            $killedPid = $worker->pid;
            if (!posix_kill($killedPid, SIGKILL)) {
                throw new RuntimeException('Unable to inject a real SIGKILL during native worker autoscaling.');
            }

            return;
        }
    }
    throw new RuntimeException('No current worker was available for concurrent SIGKILL.');
});
$loop->delay(6.4, static fn() => $supervisor->reload());
$loop->delay(13.2, static function () use ($supervisor): void {
    // A graceful manual recycle can coincide with scale-in or finish during it.
    $supervisor->recycle('c3-concurrent-fault', 0);
});
$loop->delay(18.5, static fn() => $supervisor->stop());

$supervisor->run();
$loop->cancel($observer);
$final = $supervisor->status();
$record = [
    'php' => PHP_VERSION,
    'samples' => $samples,
    'spawnCount' => count($spawned),
    'killedPid' => $killedPid,
    'restartEvents' => $restarts,
    'completedReloads' => $reloads,
    'reloadFailed' => $reloadFailed,
    'scaleInSeen' => in_array(1, $counts, true),
    'scaleOutSeen' => in_array(2, $counts, true),
    'duplicateCurrentSlotOwners' => $invalidOwnership,
    'remainingWorkers' => $final->workerCount,
];
fwrite(STDOUT, json_encode($record, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");

if ($killedPid === null || $restarts < 1 || $reloads < 1 || $reloadFailed
    || count(array_unique($spawned)) < 3 || $invalidOwnership
    || !in_array(1, $counts, true) || !in_array(2, $counts, true)
    || $final->workerCount !== 0 || $final->pendingRestartCount !== 0) {
    throw new RuntimeException('C3 concurrent autoscale, crash, reload, recycle and teardown invariants failed.');
}
