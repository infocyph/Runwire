<?php

declare(strict_types=1);

final class Http1SustainedTimeout extends RuntimeException {}

final class Http1SustainedProtocolError extends RuntimeException {}

/** @return array<string, int> */
function http1SustainedCounters(): array
{
    return [
        'requests_total' => 0,
        'completed_requests' => 0,
        'successful_requests' => 0,
        'errors_total' => 0,
        'timeouts_total' => 0,
        'validation_failures' => 0,
        'reconnects_total' => 0,
    ];
}

function http1SustainedCpuModel(): string
{
    $lines = is_readable('/proc/cpuinfo')
        ? file('/proc/cpuinfo', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)
        : false;
    if (is_array($lines)) {
        foreach ($lines as $line) {
            if (str_starts_with(strtolower($line), 'model name') && str_contains($line, ':')) {
                return trim(substr($line, strpos($line, ':') + 1));
            }
        }
    }

    return php_uname('m') ?: 'unknown';
}

/** @return list<int> */
function http1SustainedProcessChildren(int $pid): array
{
    $path = sprintf('/proc/%d/task/%d/children', $pid, $pid);
    if (!is_readable($path)) {
        return [];
    }
    $contents = file_get_contents($path);
    if (!is_string($contents) || trim($contents) === '') {
        return [];
    }

    return array_values(array_filter(
        array_map('intval', preg_split('/\s+/', trim($contents)) ?: []),
        static fn(int $child): bool => $child > 1,
    ));
}

/** @return list<int> */
function http1SustainedProcessTree(int $root): array
{
    $found = [];
    $queue = [$root];
    $seen = [$root => true];
    while ($queue !== []) {
        $current = array_shift($queue);
        if (!is_int($current)) {
            continue;
        }
        if (is_dir('/proc/' . $current)) {
            $found[] = $current;
        }
        foreach (http1SustainedProcessChildren($current) as $child) {
            if (isset($seen[$child])) {
                continue;
            }
            $seen[$child] = true;
            $queue[] = $child;
        }
    }

    return $found;
}

function http1SustainedProcessTicks(int $pid): int
{
    $path = '/proc/' . $pid . '/stat';
    if (!is_readable($path)) {
        return 0;
    }
    $stat = file_get_contents($path);
    if (!is_string($stat) || ($end = strrpos($stat, ')')) === false) {
        return 0;
    }
    $fields = preg_split('/\s+/', trim(substr($stat, $end + 1))) ?: [];

    return isset($fields[11], $fields[12]) ? (int) $fields[11] + (int) $fields[12] : 0;
}

function http1SustainedProcessRss(int $pid): int
{
    $path = '/proc/' . $pid . '/status';
    if (!is_readable($path)) {
        return 0;
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES);
    if (!is_array($lines)) {
        return 0;
    }
    foreach ($lines as $line) {
        if (!str_starts_with($line, 'VmRSS:')) {
            continue;
        }
        $parts = preg_split('/\s+/', trim($line)) ?: [];

        return isset($parts[1]) ? (int) $parts[1] * 1024 : 0;
    }

    return 0;
}

function http1SustainedTreeTicks(int $root): int
{
    return array_sum(array_map(http1SustainedProcessTicks(...), http1SustainedProcessTree($root)));
}

function http1SustainedTreeRss(int $root): int
{
    return array_sum(array_map(http1SustainedProcessRss(...), http1SustainedProcessTree($root)));
}

/** @return array{status:int, length:int, close:bool} */
function http1SustainedParseHead(string $head): array
{
    $lines = explode("\r\n", $head);
    $statusLine = array_shift($lines);
    if (!is_string($statusLine) || preg_match('/^HTTP\/1\.[01]\s+(\d{3})(?:\s|$)/', $statusLine, $matches) !== 1) {
        throw new Http1SustainedProtocolError('Response status line is invalid.');
    }

    $length = null;
    $close = false;
    foreach ($lines as $line) {
        if (!str_contains($line, ':')) {
            continue;
        }
        [$name, $value] = explode(':', $line, 2);
        $name = strtolower(trim($name));
        $value = trim($value);
        if ($name === 'content-length') {
            if ($value === '' || preg_match('/^\d+$/', $value) !== 1) {
                throw new Http1SustainedProtocolError('Response has an invalid Content-Length.');
            }
            $parsed = (int) $value;
            if ($length !== null && $parsed !== $length) {
                throw new Http1SustainedProtocolError('Response has conflicting Content-Length fields.');
            }
            $length = $parsed;
        } elseif ($name === 'connection') {
            $tokens = array_map(static fn(string $token): string => strtolower(trim($token)), explode(',', $value));
            $close = $close || in_array('close', $tokens, true);
        }
    }
    if ($length === null) {
        throw new Http1SustainedProtocolError('Response omitted Content-Length.');
    }

    return ['status' => (int) $matches[1], 'length' => $length, 'close' => $close];
}

/** @return resource */
function http1SustainedConnect(string $host, int $port)
{
    $errno = 0;
    $error = '';
    $socket = stream_socket_client(sprintf('tcp://%s:%d', $host, $port), $errno, $error, 2.0);
    if (!is_resource($socket)) {
        if ($errno === 110 || str_contains(strtolower($error), 'timed out')) {
            throw new Http1SustainedTimeout($error !== '' ? $error : 'Connection timed out.');
        }

        throw new RuntimeException($error !== '' ? $error : 'Unable to connect to benchmark server.');
    }
    stream_set_blocking($socket, false);
    stream_set_write_buffer($socket, 0);

    return $socket;
}

/**
 * @param array<string, int> $counter
 * @return array<string, mixed>|null
 */
function http1SustainedOpenClient(string $host, int $port, array &$counter): ?array
{
    try {
        $stream = http1SustainedConnect($host, $port);
    } catch (Http1SustainedTimeout) {
        ++$counter['requests_total'];
        ++$counter['timeouts_total'];

        return null;
    } catch (Throwable) {
        ++$counter['requests_total'];
        ++$counter['errors_total'];

        return null;
    }

    return [
        'stream' => $stream,
        'write_offset' => 0,
        'buffer' => '',
        'head' => null,
        'request_started' => 0,
        'request_deadline' => 0.0,
        'active' => true,
    ];
}

/** @param array<string, mixed> $client @param array<string, int> $counter */
function http1SustainedPrepareRequest(array &$client, array &$counter): void
{
    ++$counter['requests_total'];
    $client['write_offset'] = 0;
    $client['buffer'] = '';
    $client['head'] = null;
    $client['request_started'] = hrtime(true);
    $client['request_deadline'] = microtime(true) + 2.0;
}

/** @param array<string, mixed> $client */
function http1SustainedCloseClient(array &$client): void
{
    if (isset($client['stream']) && is_resource($client['stream'])) {
        fclose($client['stream']);
    }
    $client['active'] = false;
}

/** @param array<int, int> $histogram */
function http1SustainedRecordLatency(array &$histogram, int $started): void
{
    $micros = max(1, (int) round((hrtime(true) - $started) / 1_000));
    $bucket = max(1, intdiv($micros, 10) * 10);
    $histogram[$bucket] = ($histogram[$bucket] ?? 0) + 1;
}

/**
 * @param array<int, array<string, mixed>> $clients
 * @param array<string, int> $counter
 */
function http1SustainedReconnect(array &$clients, int $id, string $host, int $port, float $deadline, array &$counter): void
{
    http1SustainedCloseClient($clients[$id]);
    if (microtime(true) >= $deadline) {
        return;
    }
    ++$counter['reconnects_total'];
    $client = http1SustainedOpenClient($host, $port, $counter);
    if ($client === null) {
        return;
    }
    http1SustainedPrepareRequest($client, $counter);
    $clients[$id] = $client;
}

/** @param array<string, mixed> $client */
function http1SustainedReadChunk(array &$client): void
{
    $chunk = fread($client['stream'], 8192);
    if ($chunk === false) {
        throw new Http1SustainedProtocolError('Response read failed.');
    }
    if ($chunk === '') {
        if (feof($client['stream'])) {
            throw new Http1SustainedProtocolError('Response ended before completion.');
        }

        return;
    }
    $client['buffer'] .= $chunk;
    if (strlen($client['buffer']) > 1_048_576) {
        throw new Http1SustainedProtocolError('Response exceeded the benchmark safety bound.');
    }
}

/** @param array<string, mixed> $client @return array{status:int, body:string, close:bool}|null */
function http1SustainedTryCompleteResponse(array &$client): ?array
{
    if ($client['head'] === null) {
        $separator = strpos($client['buffer'], "\r\n\r\n");
        if ($separator === false) {
            if (strlen($client['buffer']) > 65_536) {
                throw new Http1SustainedProtocolError('Response headers exceeded 64 KiB.');
            }

            return null;
        }
        $client['head'] = http1SustainedParseHead(substr($client['buffer'], 0, $separator));
        $client['buffer'] = substr($client['buffer'], $separator + 4);
    }

    $length = (int) $client['head']['length'];
    $received = strlen($client['buffer']);
    if ($received < $length) {
        return null;
    }
    if ($received > $length) {
        throw new Http1SustainedProtocolError('Response included unexpected bytes after its body.');
    }

    return [
        'status' => (int) $client['head']['status'],
        'body' => $client['buffer'],
        'close' => (bool) $client['head']['close'],
    ];
}

/**
 * @param array<int, array<string, mixed>> $clients
 * @param array<string, int> $counter
 * @param array<int, int> $histogram
 * @param array{status:int, body:string, close:bool} $response
 */
function http1SustainedCompleteResponse(
    array &$clients,
    int $id,
    string $host,
    int $port,
    float $deadline,
    array &$counter,
    array &$histogram,
    bool $collectLatency,
    array $response,
): void {
    $client = &$clients[$id];
    ++$counter['completed_requests'];
    if ($response['status'] === 200 && $response['body'] === 'ok') {
        ++$counter['successful_requests'];
    } else {
        ++$counter['validation_failures'];
    }
    if ($collectLatency) {
        http1SustainedRecordLatency($histogram, (int) $client['request_started']);
    }
    if ($response['close']) {
        http1SustainedReconnect($clients, $id, $host, $port, $deadline, $counter);
    } elseif (microtime(true) < $deadline) {
        http1SustainedPrepareRequest($client, $counter);
    } else {
        http1SustainedCloseClient($client);
    }
}

/**
 * @param array<int, array<string, mixed>> $clients
 * @param array<string, int> $counter
 * @param array<int, int> $histogram
 */
function http1SustainedHandleReadable(
    array &$clients,
    int $id,
    string $host,
    int $port,
    float $deadline,
    array &$counter,
    array &$histogram,
    bool $collectLatency,
): void {
    try {
        http1SustainedReadChunk($clients[$id]);
        $response = http1SustainedTryCompleteResponse($clients[$id]);
    } catch (Throwable) {
        ++$counter['errors_total'];
        http1SustainedCloseClient($clients[$id]);

        return;
    }
    if ($response === null) {
        return;
    }
    http1SustainedCompleteResponse(
        $clients,
        $id,
        $host,
        $port,
        $deadline,
        $counter,
        $histogram,
        $collectLatency,
        $response,
    );
}

/**
 * @param array<int, array<string, mixed>> $clients
 * @param array<string, int> $counter
 * @return array{read:list<resource>, write:list<resource>, map:array<int,int>}
 */
function http1SustainedSelectSets(array &$clients, array &$counter, int $requestLength): array
{
    $read = [];
    $write = [];
    $map = [];
    $now = microtime(true);
    foreach ($clients as $id => &$client) {
        if (!($client['active'] ?? false)) {
            continue;
        }
        if ($now >= (float) $client['request_deadline']) {
            ++$counter['timeouts_total'];
            http1SustainedCloseClient($client);

            continue;
        }
        $stream = $client['stream'];
        $map[(int) $stream] = $id;
        if ((int) $client['write_offset'] < $requestLength) {
            $write[] = $stream;
        } else {
            $read[] = $stream;
        }
    }
    unset($client);

    return ['read' => $read, 'write' => $write, 'map' => $map];
}

/** @param list<resource> $write @param array<int,int> $map @param array<int,array<string,mixed>> $clients @param array<string,int> $counter */
function http1SustainedWriteReady(array $write, array $map, array &$clients, array &$counter, string $request): void
{
    foreach ($write as $stream) {
        $id = $map[(int) $stream] ?? null;
        if (!is_int($id) || !($clients[$id]['active'] ?? false)) {
            continue;
        }
        $remaining = substr($request, (int) $clients[$id]['write_offset']);
        $written = fwrite($stream, $remaining);
        if ($written === false || $written === 0) {
            ++$counter['errors_total'];
            http1SustainedCloseClient($clients[$id]);

            continue;
        }
        $clients[$id]['write_offset'] += $written;
    }
}

/**
 * @return array{counter:array<string,int>, histogram:array<int,int>, elapsed:float, peak_rss:int, start_ticks:int, end_ticks:int}
 */
function http1SustainedRun(
    string $host,
    int $port,
    int $concurrency,
    float $duration,
    int $serverPid,
    bool $collectLatency,
): array {
    $counter = http1SustainedCounters();
    $histogram = [];
    $clients = [];
    $request = "GET /benchmark HTTP/1.1\r\nHost: localhost\r\nConnection: keep-alive\r\n\r\n";
    $started = hrtime(true);
    $deadline = microtime(true) + $duration;
    $startTicks = http1SustainedTreeTicks($serverPid);
    $peakRss = http1SustainedTreeRss($serverPid);

    for ($id = 0; $id < $concurrency; ++$id) {
        $client = http1SustainedOpenClient($host, $port, $counter);
        if ($client !== null) {
            http1SustainedPrepareRequest($client, $counter);
            $clients[$id] = $client;
        }
    }

    while (true) {
        $sets = http1SustainedSelectSets($clients, $counter, strlen($request));
        $peakRss = max($peakRss, http1SustainedTreeRss($serverPid));
        if ($sets['read'] === [] && $sets['write'] === []) {
            break;
        }
        $except = [];
        $read = $sets['read'];
        $write = $sets['write'];
        if (stream_select($read, $write, $except, 0, 50_000) === false) {
            throw new RuntimeException('stream_select() failed in sustained benchmark client.');
        }
        http1SustainedWriteReady($write, $sets['map'], $clients, $counter, $request);
        foreach ($read as $stream) {
            $id = $sets['map'][(int) $stream] ?? null;
            if (is_int($id) && ($clients[$id]['active'] ?? false)) {
                http1SustainedHandleReadable(
                    $clients,
                    $id,
                    $host,
                    $port,
                    $deadline,
                    $counter,
                    $histogram,
                    $collectLatency,
                );
            }
        }
    }

    foreach ($clients as &$client) {
        if ($client['active'] ?? false) {
            http1SustainedCloseClient($client);
        }
    }
    unset($client);

    return [
        'counter' => $counter,
        'histogram' => $histogram,
        'elapsed' => (hrtime(true) - $started) / 1_000_000_000,
        'peak_rss' => max($peakRss, http1SustainedTreeRss($serverPid)),
        'start_ticks' => $startTicks,
        'end_ticks' => http1SustainedTreeTicks($serverPid),
    ];
}

/** @param array<int, int> $histogram */
function http1SustainedPercentile(array $histogram, float $fraction): float
{
    if ($histogram === []) {
        return 0.0;
    }
    ksort($histogram, SORT_NUMERIC);
    $rank = max(1, (int) ceil(array_sum($histogram) * $fraction));
    $seen = 0;
    foreach ($histogram as $micros => $count) {
        $seen += $count;
        if ($seen >= $rank) {
            return round(((int) $micros) / 1000, 3);
        }
    }

    return 0.0;
}

/** @return array<string, string> */
function http1SustainedExtensionVersions(): array
{
    $versions = [];
    foreach (explode(',', (string) getenv('RUNWIRE_EXTENSION_VERSIONS')) as $entry) {
        if (!str_contains($entry, '=')) {
            continue;
        }
        [$name, $version] = explode('=', $entry, 2);
        if ($name !== '' && $version !== '') {
            $versions[$name] = $version;
        }
    }

    return $versions;
}

/** @param array<string, int> $counter */
function http1SustainedAssertCorrect(array $counter, string $phase): void
{
    if (
        $counter['requests_total'] === 0
        || $counter['completed_requests'] !== $counter['requests_total']
        || $counter['successful_requests'] !== $counter['requests_total']
        || $counter['errors_total'] !== 0
        || $counter['timeouts_total'] !== 0
        || $counter['validation_failures'] !== 0
    ) {
        throw new RuntimeException($phase . ' received a failed, incomplete, timed-out, or invalid response.');
    }
}

/** @param list<string> $argv @return array<string, mixed> */
function http1SustainedMain(array $argv): array
{
    if (count($argv) !== 6) {
        throw new InvalidArgumentException('Usage: php http1_sustained_bench.php <port> <concurrency> <warmup-seconds> <duration-seconds> <server-pid>');
    }

    $port = (int) $argv[1];
    $concurrency = (int) $argv[2];
    $warmup = (float) $argv[3];
    $duration = (float) $argv[4];
    $serverPid = (int) $argv[5];
    if ($port < 1 || $port > 65_535 || $concurrency < 1 || $concurrency > 1024 || $warmup < 0 || $duration <= 0) {
        throw new InvalidArgumentException('Invalid sustained benchmark arguments.');
    }
    if ($serverPid < 2 || !is_dir('/proc/' . $serverPid)) {
        throw new InvalidArgumentException('server-pid must identify a running process.');
    }

    if ($warmup > 0) {
        $warm = http1SustainedRun('127.0.0.1', $port, $concurrency, $warmup, $serverPid, false);
        http1SustainedAssertCorrect($warm['counter'], 'Warm-up');
    }

    $measured = http1SustainedRun('127.0.0.1', $port, $concurrency, $duration, $serverPid, true);
    $counter = $measured['counter'];
    $failures = $counter['errors_total'] + $counter['timeouts_total'] + $counter['validation_failures'];
    $requests = $counter['requests_total'];
    $ticksPerSecond = function_exists('posix_sysconf') && defined('POSIX_SC_CLK_TCK')
        ? (int) posix_sysconf(POSIX_SC_CLK_TCK)
        : 100;
    $ticksPerSecond = max(1, $ticksPerSecond);
    $cpu = $measured['elapsed'] > 0
        ? max(0.0, ($measured['end_ticks'] - $measured['start_ticks']) / $ticksPerSecond / $measured['elapsed'] * 100)
        : 0.0;

    $result = [
        'runtime' => 'runwire-native',
        'runtime_version' => getenv('RUNWIRE_RUNTIME_VERSION') ?: '2.0-candidate',
        'runtime_build' => getenv('RUNWIRE_RUNTIME_BUILD') ?: 'unknown',
        'protocol' => 'http/1.1',
        'workload' => 'plaintext-keepalive',
        'hardware_id' => php_uname('m') . '::' . http1SustainedCpuModel(),
        'host_os' => php_uname('a'),
        'host_cpu' => http1SustainedCpuModel(),
        'php_version' => getenv('RUNWIRE_PHP_VERSION') ?: PHP_VERSION,
        'instrumentation' => getenv('RUNWIRE_INSTRUMENTATION') ?: 'ci-smoke',
        'tls' => 'off',
        'opcache' => getenv('RUNWIRE_OPCACHE') ?: 'unknown',
        'connection_reuse' => 'keep-alive',
        'extension_versions' => http1SustainedExtensionVersions(),
        'workers' => 1,
        'concurrency' => $concurrency,
        'duration_seconds' => $duration,
        'requests_total' => $requests,
        'completed_requests' => $counter['completed_requests'],
        'successful_requests' => $counter['successful_requests'],
        'throughput_rps' => $measured['elapsed'] > 0 ? round($counter['successful_requests'] / $measured['elapsed'], 3) : 0.0,
        'latency_ms' => [
            'p50' => http1SustainedPercentile($measured['histogram'], 0.50),
            'p95' => http1SustainedPercentile($measured['histogram'], 0.95),
            'p99' => http1SustainedPercentile($measured['histogram'], 0.99),
        ],
        'errors_total' => $counter['errors_total'],
        'timeouts_total' => $counter['timeouts_total'],
        'validation_failures' => $counter['validation_failures'],
        'reconnects_total' => $counter['reconnects_total'],
        'error_rate' => $requests > 0 ? round($failures / $requests, 8) : 1.0,
        'cpu_percent' => round($cpu, 3),
        'rss_peak_bytes' => $measured['peak_rss'],
        'correctness_passed' => $requests > 0
            && $counter['completed_requests'] === $requests
            && $counter['successful_requests'] === $requests
            && $failures === 0,
    ];

    return $result;
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $result = http1SustainedMain($argv);
    fwrite(STDOUT, json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
}
