<?php

declare(strict_types=1);

require __DIR__ . '/http1_sustained_bench.php';

/**
 * @param array{counter:array<string,int>,histogram:array<int,int>,elapsed:float,peak_rss:int,start_ticks:int,end_ticks:int} $run
 * @return array<string, int|float|bool>
 */
function adaptiveHttp1Phase(array $run): array
{
    http1SustainedAssertCorrect($run['counter'], 'Adaptive HTTP/1.1 phase');

    $requests = $run['counter']['requests_total'];
    $ticksPerSecond = function_exists('posix_sysconf') && defined('POSIX_SC_CLK_TCK')
        ? (int) posix_sysconf(POSIX_SC_CLK_TCK)
        : 100;
    $ticksPerSecond = max(1, $ticksPerSecond);
    $cpu = $run['elapsed'] > 0
        ? max(0.0, ($run['end_ticks'] - $run['start_ticks']) / $ticksPerSecond / $run['elapsed'] * 100)
        : 0.0;
    $p50 = http1SustainedPercentile($run['histogram'], 0.50);
    $p95 = http1SustainedPercentile($run['histogram'], 0.95);
    $p99 = http1SustainedPercentile($run['histogram'], 0.99);

    return [
        'requests' => $requests,
        'throughput_rps' => $run['elapsed'] > 0 ? round($requests / $run['elapsed'], 3) : 0.0,
        'p50_ms' => $p50,
        'p95_ms' => $p95,
        'p99_ms' => $p99,
        'cpu_percent' => round($cpu, 3),
        'rss_peak_bytes' => $run['peak_rss'],
        'fairness_ratio' => round($p99 / max(0.001, $p50), 4),
        'correctness_passed' => true,
    ];
}

if ($argc !== 5) {
    throw new InvalidArgumentException(
        'Usage: php adaptive_http1_matrix.php <port> <server-pid> <auto|fixed> <phase-seconds>',
    );
}

$port = (int) $argv[1];
$serverPid = (int) $argv[2];
$mode = strtolower($argv[3]);
$phaseSeconds = (float) $argv[4];
if ($port < 1 || $port > 65_535 || $serverPid < 2 || !is_dir('/proc/' . $serverPid)) {
    throw new InvalidArgumentException('Adaptive HTTP/1.1 benchmark target is invalid.');
}
if (!in_array($mode, ['auto', 'fixed'], true) || $phaseSeconds < 1.0) {
    throw new InvalidArgumentException('Adaptive HTTP/1.1 mode or phase duration is invalid.');
}

$transitionSeconds = max(1.0, min(2.0, $phaseSeconds / 2));
$highSeconds = max($phaseSeconds, $phaseSeconds + 2.0);

$warmup = http1SustainedRun('127.0.0.1', $port, 8, 1.0, $serverPid, false);
http1SustainedAssertCorrect($warmup['counter'], 'Adaptive HTTP/1.1 warm-up');

$phases = [
    'low_before' => adaptiveHttp1Phase(
        http1SustainedRun('127.0.0.1', $port, 8, $phaseSeconds, $serverPid, true),
    ),
    'transition_up' => adaptiveHttp1Phase(
        http1SustainedRun('127.0.0.1', $port, 256, $transitionSeconds, $serverPid, true),
    ),
    'high' => adaptiveHttp1Phase(
        http1SustainedRun('127.0.0.1', $port, 256, $highSeconds, $serverPid, true),
    ),
    'transition_down' => adaptiveHttp1Phase(
        http1SustainedRun('127.0.0.1', $port, 8, $transitionSeconds, $serverPid, true),
    ),
    'low_after' => adaptiveHttp1Phase(
        http1SustainedRun('127.0.0.1', $port, 8, $phaseSeconds, $serverPid, true),
    ),
];

$result = [
    'protocol' => 'http/1.1',
    'mode' => $mode,
    'phases' => $phases,
    'transition_latency_ms' => [
        'up_p95' => $phases['transition_up']['p95_ms'],
        'down_p95' => $phases['transition_down']['p95_ms'],
    ],
    'correctness_passed' => true,
];

fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
