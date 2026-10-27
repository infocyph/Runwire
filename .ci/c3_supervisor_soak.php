<?php

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'Infocyph\\Runwire\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $path = dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Supervisor\AutoscalePolicy;
use Infocyph\Runwire\Supervisor\Supervisor;
use Infocyph\Runwire\Supervisor\WorkerGroup;

$loop = new SelectLoop();
$supervisor = new Supervisor($loop);
$supervisor->group(new WorkerGroup(
    'c3-soak',
    2,
    static function ($context): void {
        $context->ready();
        while (!$context->stopping()) {
            usleep(20_000);
        }
    },
    automaticReady: false,
));

$samples = 0;
$supervisor->autoscale(
    'c3-soak',
    new AutoscalePolicy(minWorkers: 1, maxWorkers: 2, scaleOutQueueDepth: 1, sampleSeconds: 1.0, cooldownSeconds: 1.0),
    static function () use (&$samples): array {
        $phase = intdiv($samples++, 4);
        $busy = $phase % 2 === 1;

        return [
            'cpuRatio' => $busy ? 0.9 : 0.05,
            'queued' => $busy ? 10 : 0,
            'successfulRps' => $busy ? 100.0 : 0.0,
            'p99Milliseconds' => $busy ? 300.0 : 1.0,
        ];
    },
);

$counts = [];
$delays = [];
$baselineRss = memory_get_usage(true);
$peakChildRssBytes = 0;
$duplicateOwners = false;
$maximumFds = 0;
$nextSampleAt = microtime(true) + 0.2;
$loop->repeat(0.2, static function () use (
    $supervisor,
    &$counts,
    &$delays,
    &$peakChildRssBytes,
    &$duplicateOwners,
    &$maximumFds,
    &$nextSampleAt,
): void {
    $now = microtime(true);
    $delays[] = max(0, ($now - $nextSampleAt) * 1000);
    $nextSampleAt = $now + 0.2;

    $status = $supervisor->status();
    $counts[] = $status->currentWorkerCount;
    $slots = [];
    foreach ($status->workers as $worker) {
        if (isset($slots[$worker->slot])) {
            $duplicateOwners = true;
        }
        $slots[$worker->slot] = true;
        $path = '/proc/' . $worker->pid . '/status';
        $contents = false;
        if (is_readable($path)) {
            set_error_handler(static function (int $severity, string $message): bool {
                return $severity === E_WARNING
                    && str_starts_with($message, 'file_get_contents(')
                    && str_contains($message, 'Failed to open stream');
            });
            try {
                $contents = file_get_contents($path);
            } finally {
                restore_error_handler();
            }
        }
        if (is_string($contents) && preg_match('/^VmRSS:\\s+(\\d+)\\s+kB/m', $contents, $match) === 1) {
            $peakChildRssBytes = max($peakChildRssBytes, ((int) $match[1]) * 1024);
        }
    }
    $fds = glob('/proc/self/fd/*');
    $maximumFds = max($maximumFds, is_array($fds) ? count($fds) : 0);
});

$loop->delay(23.5, static fn() => $supervisor->stop());
$started = microtime(true);

try {
    $supervisor->run();
    sort($delays);
    $p99 = $delays[min(count($delays) - 1, (int) floor(0.99 * count($delays)))] ?? INF;
    $changes = 0;
    for ($i = 1, $count = count($counts); $i < $count; ++$i) {
        if ($counts[$i] !== $counts[$i - 1]) {
            ++$changes;
        }
    }
    $record = [
        'runtime' => PHP_VERSION,
        'samples' => $samples,
        'observedTransitions' => $changes,
        'p99LoopJitterMilliseconds' => round($p99, 3),
        'parentPeakHeapBytes' => memory_get_peak_usage(true),
        'parentHeapGrowthBytes' => memory_get_usage(true) - $baselineRss,
        'peakChildRssBytes' => $peakChildRssBytes,
        'maxParentFdCount' => $maximumFds,
        'elapsedSeconds' => round(microtime(true) - $started, 2),
        'finalWorkerCount' => $supervisor->status()->workerCount,
    ];
    fwrite(STDOUT, json_encode($record, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");

    if ($duplicateOwners || $changes < 3 || $samples < 19 || !in_array(1, $counts, true)
        || !in_array(2, $counts, true) || $p99 > 1_000.0
        || $maximumFds > 200 || memory_get_usage(true) - $baselineRss > 64 * 1024 * 1024
        || $supervisor->status()->workerCount !== 0) {
        throw new RuntimeException('Sustained C3 autoscale safety, process budget, or convergence gate failed.');
    }
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    throw $error;
}
