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
use Infocyph\Runwire\Supervisor\WorkerContext;
use Infocyph\Runwire\Supervisor\WorkerGroup;

$http = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if (!is_resource($http)) {
    throw new RuntimeException('Unable to bind adaptive HTTP traffic fixture.');
}
$address = stream_socket_get_name($http, false);
if (!is_string($address)) {
    throw new RuntimeException('Missing HTTP traffic fixture address.');
}
$loop = new SelectLoop();
$supervisor = new Supervisor($loop);
$supervisor->group(WorkerGroup::callbacks(
    name: 'http-autoscale',
    count: 2,
    factory: static function (WorkerContext $context) use ($http): void {
        stream_set_blocking($http, false);
        $stop = $context->stopStream();
        $context->ready();
        while (!$context->stopping()) {
            $read = [$http, $stop];
            $write = [];
            $except = [];
            if (stream_select($read, $write, $except, 0, 50_000) === false) {
                throw new RuntimeException('Adaptive worker accept poll failed.');
            }
            foreach ($read as $socket) {
                if ($socket === $stop) {
                    $context->consumeStopWake();
                    continue;
                }
                $client = stream_socket_accept($http, 0);
                if (!is_resource($client)) {
                    continue;
                }
                stream_set_timeout($client, 1);
                $request = fread($client, 1_024);
                if (is_string($request) && str_starts_with($request, 'GET /alive HTTP/1.1')) {
                    fwrite($client, "HTTP/1.1 200 OK\r\nContent-Length: 2\r\nConnection: close\r\n\r\nok");
                }
                fclose($client);
            }
        }
    },
    shutdownTimeoutSeconds: 0.5,
));
$sampleCount = 0;
$supervisor->autoscale(
    'http-autoscale',
    new AutoscalePolicy(minWorkers: 1, maxWorkers: 2, scaleOutQueueDepth: 1, sampleSeconds: 1.0, cooldownSeconds: 1.0),
    static function () use (&$sampleCount): array {
        $busy = intdiv($sampleCount++, 4) % 2 === 1;

        return [
            'cpuRatio' => $busy ? 0.90 : 0.05,
            'queued' => $busy ? 10 : 0,
            'successfulRps' => $busy ? 60.0 : 0.0,
            'p99Milliseconds' => $busy ? 300.0 : 1.0,
        ];
    },
);

$success = 0;
$attempted = 0;
$failures = [];
$latencies = [];
$counts = [];
$overlap = false;
$oscillation = false;
$transitions = 0;
$lastWorkerCount = null;
$lastTransitionAt = null;
$loop->repeat(0.15, static function () use (
    $supervisor, $address, &$success, &$attempted, &$failures, &$latencies, &$counts, &$overlap,
    &$oscillation, &$transitions, &$lastWorkerCount, &$lastTransitionAt,
): void {
    $snapshot = $supervisor->status();
    if ($snapshot->readyWorkerCount === 0) {
        return;
    }
    $count = $snapshot->currentWorkerCount;
    $counts[] = $count;
    if ($lastWorkerCount !== null && $count !== $lastWorkerCount) {
        $observedAt = microtime(true);
        if ($lastTransitionAt !== null && $observedAt - $lastTransitionAt < 1.0) {
            $oscillation = true;
        }
        $lastTransitionAt = $observedAt;
        ++$transitions;
    }
    $lastWorkerCount = $count;
    $seen = [];
    foreach ($snapshot->workers as $worker) {
        if (isset($seen[$worker->slot])) {
            $overlap = true;
        }
        $seen[$worker->slot] = true;
    }

    ++$attempted;
    $start = hrtime(true);
    set_error_handler(static function (int $severity, string $message): bool {
        return $severity === E_WARNING && str_starts_with($message, 'stream_socket_client():');
    });
    try {
        $stream = stream_socket_client('tcp://' . $address, $code, $error, 0.6);
    } finally {
        restore_error_handler();
    }
    if (!is_resource($stream)) {
        $failures[] = 'connect';
        return;
    }
    try {
        stream_set_timeout($stream, 1);
        fwrite($stream, "GET /alive HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n");
        $response = stream_get_contents($stream);
        $latencies[] = (hrtime(true) - $start) / 1_000_000;
        if (!is_string($response) || !str_contains($response, '200 OK')
            || !str_ends_with($response, "\r\n\r\nok")) {
            $failures[] = 'response';
            return;
        }
        ++$success;
    } finally {
        fclose($stream);
    }
});

$started = microtime(true);
$loop->delay(20.5, static fn() => $supervisor->stop());
try {
    $supervisor->run();
    sort($latencies);
    $p99 = $latencies[min(count($latencies) - 1, (int) floor(0.99 * count($latencies)))] ?? INF;
    $elapsed = microtime(true) - $started;
    $results = [
        'runtime' => PHP_VERSION,
        'success' => $success,
        'attemptedWhileReady' => $attempted,
        'failures' => count($failures),
        'transitions' => $transitions,
        'oscillationBelowCooldown' => $oscillation,
        'successfulRPM' => round($success * 60 / $elapsed, 1),
        'p99Ms' => round($p99, 3),
        'scaleInObserved' => in_array(1, $counts, true),
        'scaleOutObserved' => in_array(2, $counts, true),
        'duplicateSlots' => $overlap,
        'remainingWorkers' => $supervisor->status()->workerCount,
    ];
    fwrite(STDOUT, json_encode($results, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
    if ($success < 60 || $success !== $attempted || $failures !== [] || $p99 > 1_000.0
        || $overlap || $oscillation || $transitions < 2
        || !in_array(1, $counts, true) || !in_array(2, $counts, true)
        || $supervisor->status()->workerCount !== 0) {
        throw new RuntimeException('Live autoscale lost correct admitted HTTP responses or violated latency/slot bounds.');
    }
} finally {
    fclose($http);
}
