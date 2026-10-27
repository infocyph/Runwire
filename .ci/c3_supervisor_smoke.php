<?php

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'Infocyph\\Runwire\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file = dirname(__DIR__) . '/src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Supervisor\AutoscalePolicy;
use Infocyph\Runwire\Supervisor\Supervisor;
use Infocyph\Runwire\Supervisor\WorkerGroup;

if (!function_exists('pcntl_fork') || !function_exists('posix_getpid')) {
    fwrite(STDERR, "C3 supervisor smoke requires native pcntl and posix.\n");
    exit(2);
}

$loop = new SelectLoop();
$supervisor = new Supervisor($loop);
$supervisor->group(new WorkerGroup(
    name: 'c3-smoke',
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
    'c3-smoke',
    new AutoscalePolicy(minWorkers: 1, maxWorkers: 2, scaleOutQueueDepth: 1, sampleSeconds: 1.0, cooldownSeconds: 1.0),
    static function () use (&$samples): array {
        ++$samples;

        return [
            'cpuRatio' => $samples <= 2 ? 0.01 : 0.90,
            'queued' => $samples <= 2 ? 0 : 2,
            'successfulRps' => $samples <= 2 ? 0.0 : 10.0,
            'p99Milliseconds' => 1.0,
        ];
    },
);

$seenOne = false;
$seenTwo = false;
$duplicateSlot = false;
$observer = $loop->repeat(0.1, static function () use ($supervisor, &$seenOne, &$seenTwo, &$duplicateSlot): void {
    $status = $supervisor->status();
    $seenOne = $seenOne || $status->currentWorkerCount === 1;
    $seenTwo = $seenTwo || $status->currentWorkerCount === 2;
    $slots = [];
    foreach ($status->workers as $worker) {
        if (isset($slots[$worker->slot])) {
            $duplicateSlot = true;
        }
        $slots[$worker->slot] = true;
    }
});
$loop->delay(6.5, static fn() => $supervisor->stop());

try {
    $supervisor->run();
    $loop->cancel($observer);
    $status = $supervisor->status();
    if (!$seenOne || !$seenTwo || $duplicateSlot || $status->workerCount !== 0 || $samples < 4) {
        throw new RuntimeException(sprintf(
            'C3 native autoscale smoke failed: min=%d max=%d duplicate=%d remaining=%d samples=%d.',
            (int) $seenOne,
            (int) $seenTwo,
            (int) $duplicateSlot,
            $status->workerCount,
            $samples,
        ));
    }

    echo "C3 native autoscale smoke passed.\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
