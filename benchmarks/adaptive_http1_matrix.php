<?php

declare(strict_types=1);

require __DIR__ . '/http1_sustained_bench.php';

/** @return array{user:float,system:float} */
function adaptiveHttp1ClientCpu(): array
{
    $usage = getrusage();

    return [
        'user' => ((int) ($usage['ru_utime.tv_sec'] ?? 0)) + ((int) ($usage['ru_utime.tv_usec'] ?? 0)) / 1_000_000,
        'system' => ((int) ($usage['ru_stime.tv_sec'] ?? 0)) + ((int) ($usage['ru_stime.tv_usec'] ?? 0)) / 1_000_000,
    ];
}

/**
 * @param array{counter:array<string,int>,histogram:array<int,int>,slot_completed:array<int,int>,elapsed:float,peak_rss:int,start_ticks:int,end_ticks:int} $run
 * @param array{user:float,system:float} $cpuBefore
 * @param array{user:float,system:float} $cpuAfter
 * @return array<string, int|float|bool|array<string,int>>
 */
function adaptiveHttp1Phase(array $run, array $cpuBefore, array $cpuAfter): array
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
    $clientCpuSeconds = max(
        0.0,
        ($cpuAfter['user'] + $cpuAfter['system']) - ($cpuBefore['user'] + $cpuBefore['system']),
    );
    $p50 = http1SustainedPercentile($run['histogram'], 0.50);
    $p95 = http1SustainedPercentile($run['histogram'], 0.95);
    $p99 = http1SustainedPercentile($run['histogram'], 0.99);
    $nonzeroSlots = array_values(array_filter(
        $run['slot_completed'],
        static fn(int $count): bool => $count > 0,
    ));
    $fairness = $nonzeroSlots === []
        ? INF
        : max($nonzeroSlots) / max(1, min($nonzeroSlots));

    return [
        'requests' => $requests,
        'errors' => 0,
        'duration_seconds' => $run['elapsed'],
        'throughput_rps' => $run['elapsed'] > 0 ? round($requests / $run['elapsed'], 3) : 0.0,
        'p50_ms' => $p50,
        'p95_ms' => $p95,
        'p99_ms' => $p99,
        'cpu_percent' => round($cpu, 3),
        'client_cpu_percent' => $run['elapsed'] > 0
            ? round($clientCpuSeconds / $run['elapsed'] * 100, 3)
            : 0.0,
        'rss_peak_bytes' => $run['peak_rss'],
        'fairness_ratio' => round($fairness, 4),
        'correctness_passed' => count($nonzeroSlots) === count($run['slot_completed']),
        'observed' => [
            'connections' => count($run['slot_completed']) + $run['counter']['reconnects_total'],
        ],
    ];
}

/** @return array<string, mixed> */
function adaptiveHttp1Workload(): array
{
    $raw = getenv('RUNWIRE_ADAPTIVE_CASE');
    if (!is_string($raw) || $raw === '') {
        return [
            'id' => 'plaintext-2-bytes',
            'protocol' => 'http1',
            'tls' => false,
            'payload_bytes' => 2,
            'keepalive' => 800,
            'scenario' => 'steady',
            'low' => 8,
            'medium' => 32,
            'high' => 256,
        ];
    }

    $case = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($case)
        || ($case['protocol'] ?? null) !== 'http1'
        || !isset($case['id'], $case['payload_bytes'], $case['keepalive'], $case['low'], $case['medium'], $case['high'])
    ) {
        throw new InvalidArgumentException('RUNWIRE_ADAPTIVE_CASE is not a valid HTTP/1.1 promotion case.');
    }

    return $case;
}

/** @return array<string, mixed> */
function adaptiveHttp1RunPhase(
    int $port,
    int $serverPid,
    int $concurrency,
    float $duration,
    array $case,
): array {
    $payloadBytes = (int) $case['payload_bytes'];
    $before = adaptiveHttp1ClientCpu();
    $run = http1SustainedRun(
        '127.0.0.1',
        $port,
        $concurrency,
        $duration,
        $serverPid,
        true,
        (bool) ($case['tls'] ?? false),
        '/benchmark/' . $payloadBytes,
        str_repeat('x', $payloadBytes),
        (int) $case['keepalive'],
    );
    $after = adaptiveHttp1ClientCpu();

    $result = adaptiveHttp1Phase($run, $before, $after);
    $result['concurrency'] = $concurrency;
    $result['requested_duration_seconds'] = $duration;

    return $result;
}

if ($argc !== 5) {
    throw new InvalidArgumentException(
        'Usage: php adaptive_http1_matrix.php <port> <server-pid> <auto|fixed|latency|throughput> <phase-seconds>',
    );
}

$port = (int) $argv[1];
$serverPid = (int) $argv[2];
$mode = strtolower($argv[3]);
$phaseSeconds = (float) $argv[4];
if ($port < 1 || $port > 65_535 || $serverPid < 2 || !is_dir('/proc/' . $serverPid)) {
    throw new InvalidArgumentException('Adaptive HTTP/1.1 benchmark target is invalid.');
}
if (!in_array($mode, ['auto', 'fixed', 'latency', 'throughput'], true)
    || !is_finite($phaseSeconds)
    || $phaseSeconds < 1.0) {
    throw new InvalidArgumentException('Adaptive HTTP/1.1 mode or phase duration is invalid.');
}

$case = adaptiveHttp1Workload();
$warmupSeconds = max(1.0, (float) (getenv('RUNWIRE_ADAPTIVE_WARMUP_SECONDS') ?: 1));
$warmup = http1SustainedRun(
    '127.0.0.1',
    $port,
    (int) $case['low'],
    $warmupSeconds,
    $serverPid,
    false,
    (bool) ($case['tls'] ?? false),
    '/benchmark/' . (int) $case['payload_bytes'],
    str_repeat('x', (int) $case['payload_bytes']),
    (int) $case['keepalive'],
);
http1SustainedAssertCorrect($warmup['counter'], 'Adaptive HTTP/1.1 warm-up');

$transitionSeconds = min(2.0, $phaseSeconds);
$phasePlan = [
    'low_before' => [(int) $case['low'], $phaseSeconds],
    'medium' => [(int) $case['medium'], $phaseSeconds],
    'transition_up' => [(int) $case['high'], $transitionSeconds],
    'high' => [(int) $case['high'], $phaseSeconds],
    'transition_down' => [(int) $case['low'], $transitionSeconds],
    'low_after' => [(int) $case['low'], $phaseSeconds],
];

$phases = [];
foreach ($phasePlan as $name => [$concurrency, $duration]) {
    $phases[$name] = adaptiveHttp1RunPhase($port, $serverPid, $concurrency, $duration, $case);
}

$result = [
    'protocol' => 'http/1.1',
    'mode' => $mode,
    'workload' => (string) $case['id'],
    'payload_bytes' => (int) $case['payload_bytes'],
    'runtime_build' => getenv('RUNWIRE_ADAPTIVE_BUILD') ?: 'unrecorded',
    'environment' => getenv('RUNWIRE_ADAPTIVE_ENVIRONMENT') ?: 'unrecorded',
    'warmup_seconds' => $warmupSeconds,
    'case' => $case,
    'trial' => (int) (getenv('RUNWIRE_ADAPTIVE_TRIAL') ?: 0),
    'phases' => $phases,
    'correctness_passed' => array_reduce(
        $phases,
        static fn(bool $carry, array $phase): bool => $carry && ($phase['correctness_passed'] ?? false) === true,
        true,
    ),
];

fwrite(
    STDOUT,
    json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL,
);

if (!$result['correctness_passed']) {
    throw new RuntimeException('Adaptive HTTP/1.1 benchmark failed correctness.');
}
