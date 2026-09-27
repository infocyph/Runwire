<?php

declare(strict_types=1);

function h2loadDurationMs(string $value): ?float
{
    if (preg_match('/^([0-9.]+)(us|ms|s)$/', trim($value), $matches) !== 1) {
        return null;
    }

    $number = (float) $matches[1];

    return match ($matches[2]) {
        'us' => $number / 1000,
        'ms' => $number,
        's' => $number * 1000,
    };
}

if ($argc !== 7) {
    throw new InvalidArgumentException(
        'Usage: php h2load_parse.php <raw-log> <tcp-nodelay> <payload> <streams> <connections> <label>',
    );
}

$raw = file_get_contents($argv[1]);
if (!is_string($raw)) {
    throw new RuntimeException('Unable to read h2load output.');
}

if (preg_match('/finished in\s+[^,]+,\s*([0-9.]+)\s*req\/s/', $raw, $finished) !== 1) {
    throw new RuntimeException('Unable to parse h2load requests per second.');
}
if (preg_match(
    '/requests:\s+(\d+) total,\s+(\d+) started,\s+(\d+) done,\s+(\d+) succeeded,\s+(\d+) failed/',
    $raw,
    $requests,
) !== 1) {
    throw new RuntimeException('Unable to parse h2load request counts.');
}

$timing = null;
if (preg_match('/time for request:\s+(\S+)\s+(\S+)\s+(\S+)\s+(\S+)/', $raw, $time) === 1) {
    $timing = [
        'min_ms' => h2loadDurationMs($time[1]),
        'max_ms' => h2loadDurationMs($time[2]),
        'mean_ms' => h2loadDurationMs($time[3]),
        'sd_ms' => h2loadDurationMs($time[4]),
    ];
}

$result = [
    'label' => $argv[6],
    'tcp_nodelay' => $argv[2] === '1',
    'payload_bytes' => (int) $argv[3],
    'max_concurrent_streams' => (int) $argv[4],
    'connections' => (int) $argv[5],
    'throughput_rps' => (float) $finished[1],
    'requests_total' => (int) $requests[1],
    'requests_done' => (int) $requests[3],
    'requests_succeeded' => (int) $requests[4],
    'requests_failed' => (int) $requests[5],
    'request_timing' => $timing,
    'correctness_passed' => (int) $requests[5] === 0 && (int) $requests[3] === (int) $requests[4],
];

fwrite(STDOUT, json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
