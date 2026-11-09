<?php

declare(strict_types=1);

require_once __DIR__ . '/http1_bench_workload.php';

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
        'connect_errors' => 0,
        'response_read_errors' => 0,
        'write_errors' => 0,
        'write_would_block' => 0,
        'socket_write_calls' => 0,
        'socket_write_bytes' => 0,
        'socket_read_calls' => 0,
        'socket_read_bytes' => 0,
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
            if (str_starts_with(strtolower($line), 'model name')) {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    return trim($parts[1]);
                }
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

/** @return array{syscr:int,syscw:int,rchar:int,wchar:int} */
function http1SustainedProcessIoCounters(int $pid): array
{
    $totals = ['syscr' => 0, 'syscw' => 0, 'rchar' => 0, 'wchar' => 0];
    $lines = is_readable('/proc/' . $pid . '/io')
        ? file('/proc/' . $pid . '/io', FILE_IGNORE_NEW_LINES)
        : false;
    if (!is_array($lines)) {
        return $totals;
    }
    foreach ($lines as $line) {
        $parts = explode(':', $line, 2);
        if (count($parts) !== 2) {
            continue;
        }
        $key = $parts[0];
        $raw = $parts[1];
        if (in_array($key, ['syscr', 'syscw', 'rchar', 'wchar'], true) && preg_match('/^\\d+$/D', trim($raw)) === 1) {
            $totals[$key] = $totals[$key] + max(0, (int) trim($raw));
        }
    }

    return $totals;
}

/** @return array{syscr:int,syscw:int,rchar:int,wchar:int} */
function http1SustainedTreeIoCounters(int $root): array
{
    $totals = ['syscr' => 0, 'syscw' => 0, 'rchar' => 0, 'wchar' => 0];
    foreach (http1SustainedProcessTree($root) as $pid) {
        $usage = http1SustainedProcessIoCounters($pid);
        foreach (['syscr', 'syscw', 'rchar', 'wchar'] as $key) {
            $totals[$key] += $usage[$key];
        }
    }

    return $totals;
}

function http1SustainedTreeRss(int $root): int
{
    return array_sum(array_map(http1SustainedProcessRss(...), http1SustainedProcessTree($root)));
}

/** @throws Http1SustainedProtocolError */
function http1SustainedContentLength(string $value, ?int $previous): int
{
    if ($value === '' || preg_match('/^\d+$/', $value) !== 1) {
        throw new Http1SustainedProtocolError('Response has an invalid Content-Length.');
    }
    $parsed = (int) $value;
    if ($previous !== null && $parsed !== $previous) {
        throw new Http1SustainedProtocolError('Response has conflicting Content-Length fields.');
    }

    return $parsed;
}

/**
 * @return array{status:int, length:int, close:bool}
 * @throws \Http1SustainedProtocolError
 */
function http1SustainedParseHead(string $head): array
{
    $lines = explode("\r\n", $head);
    $statusLine = array_shift($lines);
    if (preg_match('/^HTTP\/1\.[01]\s+(\d{3})(?:\s|$)/', $statusLine, $matches) !== 1) {
        throw new Http1SustainedProtocolError('Response status line is invalid.');
    }

    $length = null;
    $close = false;
    foreach ($lines as $line) {
        $parts = explode(':', $line, 2);
        if (count($parts) !== 2) {
            continue;
        }
        $name = strtolower(trim($parts[0]));
        $value = $parts[1];
        $value = trim($value);
        if ($name === 'content-length') {
            $length = http1SustainedContentLength($value, $length);
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

/**
 * @return resource
 * @throws RuntimeException
 */
function http1SustainedContext(bool $tls)
{
    if (!$tls) {
        return stream_context_create();
    }
    $caFile = getenv('RUNWIRE_BENCH_TLS_CA');
    if (!is_string($caFile) || $caFile === '' || !is_file($caFile)) {
        throw new RuntimeException('TLS certification requires an existing trusted CA file.');
    }

    return stream_context_create(['ssl' => [
        'verify_peer' => true,
        'verify_peer_name' => true,
        'peer_name' => 'localhost',
        'SNI_enabled' => true,
        'allow_self_signed' => false,
        'cafile' => $caFile,
        'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT,
    ]]);
}

/**
 * @return resource
 * @throws \Http1SustainedTimeout
 * @throws \RuntimeException
 */
function http1SustainedConnect(string $host, int $port)
{
    $errno = 0;
    $error = '';
    $tls = getenv('RUNWIRE_BENCH_TLS') === '1';
    $context = http1SustainedContext($tls);
    $socket = stream_socket_client(
        sprintf('%s://%s:%d', $tls ? 'tls' : 'tcp', $host, $port),
        $errno,
        $error,
        2.0,
        STREAM_CLIENT_CONNECT,
        $context,
    );
    if (!is_resource($socket)) {
        http1SustainedConnectFailure($errno, $error);
    }
    stream_set_blocking($socket, false);
    stream_set_write_buffer($socket, 0);

    return $socket;
}

/**
 * @throws Http1SustainedTimeout
 * @throws RuntimeException
 */
function http1SustainedConnectFailure(?int $errno, ?string $error): never
{
    if ($errno === 110 || str_contains(strtolower($error ?? ''), 'timed out')) {
        throw new Http1SustainedTimeout($error !== null && $error !== '' ? $error : 'Connection timed out.');
    }

    throw new RuntimeException($error !== null && $error !== '' ? $error : 'Unable to connect to benchmark server.');
}

/**
 * @param array<string, int> $counter
 * @return array{stream:resource|closed-resource,write_offset:int,buffer:string,head:array{status:int,length:int,close:bool}|null,request_started:int|float,request_deadline:float,active:bool}|null
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
        ++$counter['connect_errors'];

        return null;
    }

    return http1SustainedClient($stream);
}

/**
 * @param resource $stream
 * @return array{stream:resource|closed-resource,write_offset:int,buffer:string,head:array{status:int,length:int,close:bool}|null,request_started:int|float,request_deadline:float,active:bool}
 */
function http1SustainedClient(mixed $stream): array
{
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

/**
 * @param array{stream:resource|closed-resource,write_offset:int,buffer:string,head:array{status:int,length:int,close:bool}|null,request_started:int|float,request_deadline:float,active:bool} $client
 * @param array<string, int> $counter
 */
function http1SustainedPrepareRequest(array &$client, array &$counter): void
{
    ++$counter['requests_total'];
    $client['write_offset'] = 0;
    $client['buffer'] = '';
    $client['head'] = null;
    // Admission does not begin a request: under high concurrency a stream
    // may wait for its first write. The strict two-second request budget
    // starts when the request is actually sent, not while queued.
    $client['request_started'] = 0;
    $client['request_deadline'] = 0.0;
}

/** @param array{stream:resource|closed-resource,write_offset:int,buffer:string,head:array{status:int,length:int,close:bool}|null,request_started:int|float,request_deadline:float,active:bool} $client */
function http1SustainedCloseClient(array &$client): void
{
    if (is_resource($client['stream'])) {
        fclose($client['stream']);
    }
    $client['active'] = false;
}

/** @param array<int, int> $histogram */
function http1SustainedRecordLatency(array &$histogram, int|float $started): void
{
    $micros = max(1, (int) round(((float) hrtime(true) - (float) $started) / 1_000.0));
    $bucket = max(1, intdiv($micros, 10) * 10);
    $histogram[$bucket] = ($histogram[$bucket] ?? 0) + 1;
}

/**
 * @param array<int, array{stream:resource|closed-resource,write_offset:int,buffer:string,head:array{status:int,length:int,close:bool}|null,request_started:int|float,request_deadline:float,active:bool}> $clients
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

/**
 * @param array{stream:resource|closed-resource,...<array-key,mixed>} $client
 * @return resource
 * @throws RuntimeException
 */
function http1SustainedClientStream(array $client)
{
    $stream = $client['stream'];
    if (!is_resource($stream)) {
        throw new RuntimeException('The active benchmark client has no live stream.');
    }

    return $stream;
}
/**
 * @param array{stream:resource|closed-resource,write_offset:int,buffer:string,head:array{status:int,length:int,close:bool}|null,request_started:int|float,request_deadline:float,active:bool} $client
 * @param array<string, int> $counter
 * @throws \Http1SustainedProtocolError
 * @throws \RuntimeException
 */
function http1SustainedReadChunk(array &$client, array &$counter): void
{
    $stream = http1SustainedClientStream($client);
    ++$counter['socket_read_calls'];
    $chunk = fread($stream, 8192);
    if (is_string($chunk)) {
        $counter['socket_read_bytes'] += strlen($chunk);
    }
    if ($chunk === false) {
        throw new Http1SustainedProtocolError('Response read failed.');
    }
    if ($chunk === '') {
        if (feof($stream)) {
            throw new Http1SustainedProtocolError('Response ended before completion.');
        }

        return;
    }
    $client['buffer'] .= $chunk;
    if (strlen($client['buffer']) > 1_048_576) {
        throw new Http1SustainedProtocolError('Response exceeded the benchmark safety bound.');
    }
}

/**
 * @param array{stream:resource|closed-resource,write_offset:int,buffer:string,head:array{status:int,length:int,close:bool}|null,request_started:int|float,request_deadline:float,active:bool} $client
 * @return array{status:int, body:string, close:bool}|null
 * @throws \Http1SustainedProtocolError
 */
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

    $length = $client['head']['length'];
    $received = strlen($client['buffer']);
    if ($received < $length) {
        return null;
    }
    if ($received > $length) {
        throw new Http1SustainedProtocolError('Response included unexpected bytes after its body.');
    }

    return [
        'status' => $client['head']['status'],
        'body' => $client['buffer'],
        'close' => $client['head']['close'],
    ];
}

/**
 * @param array<int, array{stream:resource|closed-resource,write_offset:int,buffer:string,head:array{status:int,length:int,close:bool}|null,request_started:int|float,request_deadline:float,active:bool}> $clients
 * @param array<string, int> $counter
 * @param array<int, int> $histogram
 * @param array<int, int> $slotCompleted
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
    array &$slotCompleted,
    bool $collectLatency,
    array $response,
    string $expectedBody,
    bool $closeRequested,
): void {
    $client = &$clients[$id];
    ++$counter['completed_requests'];
    ++$slotCompleted[$id];
    if ($response['status'] === 200 && $response['body'] === $expectedBody) {
        ++$counter['successful_requests'];
    } else {
        ++$counter['validation_failures'];
    }
    if ($collectLatency) {
        http1SustainedRecordLatency($histogram, $client['request_started']);
    }
    if ($response['close'] || $closeRequested) {
        http1SustainedReconnect($clients, $id, $host, $port, $deadline, $counter);
    } elseif (microtime(true) < $deadline) {
        http1SustainedPrepareRequest($client, $counter);
    } else {
        http1SustainedCloseClient($client);
    }
}

/**
 * @param array<int, array{stream:resource|closed-resource,write_offset:int,buffer:string,head:array{status:int,length:int,close:bool}|null,request_started:int|float,request_deadline:float,active:bool}> $clients
 * @param array<string, int> $counter
 * @param array<int, int> $histogram
 * @param array<int, int> $slotCompleted
 */
function http1SustainedHandleReadable(
    array &$clients,
    int $id,
    string $host,
    int $port,
    float $deadline,
    array &$counter,
    array &$histogram,
    array &$slotCompleted,
    bool $collectLatency,
    string $expectedBody,
    bool $closeRequested,
): void {
    try {
        http1SustainedReadChunk($clients[$id], $counter);
        $response = http1SustainedTryCompleteResponse($clients[$id]);
    } catch (Throwable) {
        ++$counter['errors_total'];
        ++$counter['response_read_errors'];
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
        $slotCompleted,
        $collectLatency,
        $response,
        $expectedBody,
        $closeRequested,
    );
}

/**
 * @param array<int, array{stream:resource|closed-resource,write_offset:int,buffer:string,head:array{status:int,length:int,close:bool}|null,request_started:int|float,request_deadline:float,active:bool}> $clients
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
        if (!($client['active'])) {
            continue;
        }
        if ($client['request_deadline'] > 0.0 && $now >= $client['request_deadline']) {
            ++$counter['timeouts_total'];
            http1SustainedCloseClient($client);

            continue;
        }
        $stream = http1SustainedClientStream($client);
        $map[(int) $stream] = $id;
        if ($client['write_offset'] < $requestLength) {
            $write[] = $stream;
        } else {
            $read[] = $stream;
        }
    }
    unset($client);

    return ['read' => $read, 'write' => $write, 'map' => $map];
}

/**
 * @param array<array-key,resource> $write
 * @param array<int,int> $map
 * @param array<int,array{stream:resource|closed-resource,write_offset:int,buffer:string,head:array{status:int,length:int,close:bool}|null,request_started:int|float,request_deadline:float,active:bool}> $clients
 * @param array<string,int> $counter
 */
function http1SustainedWriteReady(array $write, array $map, array &$clients, array &$counter, string $request): void
{
    foreach ($write as $stream) {
        $id = $map[(int) $stream] ?? null;
        if (!is_int($id) || !($clients[$id]['active'])) {
            continue;
        }
        $remaining = substr($request, $clients[$id]['write_offset']);
        if ($clients[$id]['request_deadline'] === 0.0) {
            $clients[$id]['request_started'] = hrtime(true);
            $clients[$id]['request_deadline'] = microtime(true) + 2.0;
        }
        ++$counter['socket_write_calls'];
        $written = fwrite($stream, $remaining);
        if ($written === false) {
            ++$counter['errors_total'];
            ++$counter['write_errors'];
            http1SustainedCloseClient($clients[$id]);

            continue;
        }
        if ($written === 0) {
            // A writable descriptor may race with another writer or hit a
            // transient send-buffer limit. Let the existing select() and
            // request deadline determine forward progress, not false failure.
            ++$counter['write_would_block'];

            continue;
        }
        $counter['socket_write_bytes'] += $written;
        $clients[$id]['write_offset'] += $written;
    }
}

/**
 * @param array<string,int> $counter
 * @return array<int,array{stream:resource|closed-resource,write_offset:int,buffer:string,head:array{status:int,length:int,close:bool}|null,request_started:int|float,request_deadline:float,active:bool}>
 */
function http1SustainedAdmitClients(string $host, int $port, int $concurrency, array &$counter): array
{
    $clients = [];
    for ($id = 0; $id < $concurrency; ++$id) {
        $client = http1SustainedOpenClient($host, $port, $counter);
        if ($client !== null) {
            http1SustainedPrepareRequest($client, $counter);
            $clients[$id] = $client;
        }
    }

    return $clients;
}

/** @param array<int,array{stream:resource|closed-resource,write_offset:int,buffer:string,head:array{status:int,length:int,close:bool}|null,request_started:int|float,request_deadline:float,active:bool}> $clients */
function http1SustainedCloseClients(array &$clients): void
{
    foreach ($clients as &$client) {
        if ($client['active']) {
            http1SustainedCloseClient($client);
        }
    }
    unset($client);
}

/**
 * @return array{counter:array<string,int>, histogram:array<int,int>, slot_completed:array<int,int>, elapsed:float, peak_rss:int, start_ticks:int, end_ticks:int, io_start:array<string,int>, io_end:array<string,int>}
 * @throws \InvalidArgumentException
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
    $slotCompleted = array_fill(0, $concurrency, 0);
    $request = http1BenchRequest();
    $expectedBody = http1BenchResponseBody();
    $closeRequested = http1BenchConnectionMode() === 'close';
    $started = hrtime(true);
    $deadline = microtime(true) + $duration;
    $startTicks = http1SustainedTreeTicks($serverPid);
    $startIo = http1SustainedTreeIoCounters($serverPid);
    $peakRss = http1SustainedTreeRss($serverPid);

    $clients = http1SustainedAdmitClients($host, $port, $concurrency, $counter);

    try {
        $sets = http1SustainedSelectSets($clients, $counter, strlen($request));
        $peakRss = max($peakRss, http1SustainedTreeRss($serverPid));
        while ($sets['read'] !== [] || $sets['write'] !== []) {
            $except = [];
            $read = $sets['read'];
            $write = $sets['write'];
            if (stream_select($read, $write, $except, 0, 50_000) === false) {
                throw new RuntimeException('stream_select() failed in sustained benchmark client.');
            }
            http1SustainedWriteReady($write, $sets['map'], $clients, $counter, $request);
            foreach ($read as $stream) {
                $id = $sets['map'][(int) $stream] ?? null;
                if (is_int($id) && ($clients[$id]['active'])) {
                    http1SustainedHandleReadable(
                        $clients,
                        $id,
                        $host,
                        $port,
                        $deadline,
                        $counter,
                        $histogram,
                        $slotCompleted,
                        $collectLatency,
                        $expectedBody,
                        $closeRequested,
                    );
                }
            }
            $sets = http1SustainedSelectSets($clients, $counter, strlen($request));
            $peakRss = max($peakRss, http1SustainedTreeRss($serverPid));
        }
    } finally {
        http1SustainedCloseClients($clients);
    }

    return [
        'counter' => $counter,
        'histogram' => $histogram,
        'slot_completed' => $slotCompleted,
        'elapsed' => ((float) hrtime(true) - (float) $started) / 1_000_000_000.0,
        'peak_rss' => max($peakRss, http1SustainedTreeRss($serverPid)),
        'start_ticks' => $startTicks,
        'end_ticks' => http1SustainedTreeTicks($serverPid),
        'io_start' => $startIo,
        'io_end' => http1SustainedTreeIoCounters($serverPid),
    ];
}

/** @param array<int, int> $histogram */
function http1SustainedPercentile(array $histogram, float $fraction): float
{
    if ($histogram === []) {
        return 0.0;
    }
    ksort($histogram, SORT_NUMERIC);
    $rank = max(1, (int) ceil((float) array_sum($histogram) * $fraction));
    $seen = 0;
    foreach ($histogram as $micros => $count) {
        $seen += $count;
        if ($seen >= $rank) {
            return round($micros / 1000, 3);
        }
    }

    return 0.0;
}

/** @return array<string, string> */
function http1SustainedExtensionVersions(): array
{
    $versions = [];
    foreach (explode(',', (string) getenv('RUNWIRE_EXTENSION_VERSIONS')) as $entry) {
        $parts = explode('=', $entry, 2);
        if (count($parts) !== 2) {
            continue;
        }
        $name = $parts[0];
        $version = $parts[1];
        if ($name !== '' && $version !== '') {
            $versions[$name] = $version;
        }
    }

    return $versions;
}

/**
 * @param array<string, int> $counter
 * @throws \RuntimeException
 */
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
        throw new RuntimeException(
            $phase . ' received a failed, incomplete, timed-out, or invalid response. Counters: '
            . json_encode($counter, JSON_THROW_ON_ERROR),
        );
    }
}

/** Resolve a CLI benchmark metadata field, retaining the legacy empty/zero fallback. */
function http1SustainedEnvironment(string $name, string $fallback): string
{
    $value = getenv($name);

    return $value === false || $value === '' || $value === '0' ? $fallback : $value;
}

/**
 * @param list<string> $argv
 * @return array{port:int,concurrency:int,warmup:float,duration:float,server_pid:int}
 * @throws InvalidArgumentException
 */
function http1SustainedArguments(array $argv): array
{
    if (count($argv) !== 6) {
        throw new InvalidArgumentException('Usage: php http1_sustained_bench.php <port> <concurrency> <warmup-seconds> <duration-seconds> <server-pid>');
    }

    $port = (int) $argv[1];
    $concurrency = (int) $argv[2];
    $warmup = (float) $argv[3];
    $duration = (float) $argv[4];
    $serverPid = (int) $argv[5];
    if ($port < 1 || $port > 65_535 || $concurrency < 1 || $concurrency > 1024 || $warmup < 0 || $duration <= 0 || !is_finite($warmup) || !is_finite($duration)) {
        throw new InvalidArgumentException('Invalid sustained benchmark arguments.');
    }
    if ($serverPid < 2 || !is_dir('/proc/' . $serverPid)) {
        throw new InvalidArgumentException('server-pid must identify a running process.');
    }

    return ['port' => $port, 'concurrency' => $concurrency, 'warmup' => $warmup, 'duration' => $duration, 'server_pid' => $serverPid];
}

/** @param array{elapsed:float,start_ticks:int,end_ticks:int,...<string,mixed>} $measured */
function http1SustainedCpuPercent(array $measured): float
{
    $ticks = function_exists('posix_sysconf') && defined('POSIX_SC_CLK_TCK')
        ? posix_sysconf(POSIX_SC_CLK_TCK)
        : false;
    $ticksPerSecond = is_int($ticks) && $ticks > 0 ? $ticks : 100;

    return $measured['elapsed'] > 0
        ? max(0.0, (float) ($measured['end_ticks'] - $measured['start_ticks']) / (float) $ticksPerSecond / $measured['elapsed'] * 100.0)
        : 0.0;
}

/**
 * @param array<string,int> $counter
 * @return array{request_size_bytes:int,write_calls:int,write_bytes:int,write_would_block:int,read_calls:int,read_bytes:int,write_bytes_per_request:float,read_bytes_per_request:float,write_calls_per_request:float,read_calls_per_request:float}
 * @throws InvalidArgumentException
 */
function http1SustainedSocketIo(array $counter, int $requests): array
{
    return [
        'request_size_bytes' => strlen(http1BenchRequest()),
        'write_calls' => $counter['socket_write_calls'],
        'write_bytes' => $counter['socket_write_bytes'],
        'write_would_block' => $counter['write_would_block'],
        'read_calls' => $counter['socket_read_calls'],
        'read_bytes' => $counter['socket_read_bytes'],
        'write_bytes_per_request' => $requests > 0
            ? round($counter['socket_write_bytes'] / $requests, 3)
            : 0.0,
        'read_bytes_per_request' => $requests > 0
            ? round($counter['socket_read_bytes'] / $requests, 3)
            : 0.0,
        'write_calls_per_request' => $requests > 0
            ? round($counter['socket_write_calls'] / $requests, 6)
            : 0.0,
        'read_calls_per_request' => $requests > 0
            ? round($counter['socket_read_calls'] / $requests, 6)
            : 0.0,
    ];
}

/**
 * @param list<string> $argv
 * @return array{runtime:string,runtime_version:string,runtime_build:string,protocol:string,workload:string,hardware_id:string,host_os:string,host_cpu:string,php_version:string,instrumentation:string,tls:string,opcache:string,connection_reuse:string,response_body_bytes:int,workers:int,concurrency:int,requests_total:int,completed_requests:int,successful_requests:int,errors_total:int,timeouts_total:int,validation_failures:int,reconnects_total:int,rss_peak_bytes:int,duration_seconds:float,actual_duration_seconds:float,throughput_rps:float,error_rate:float,cpu_percent:float,extension_versions:array<string,string>,continuous_duration_passed:bool,correctness_passed:bool,latency_ms:array{p50:float,p95:float,p99:float},error_breakdown:array{connect:int,response_read:int,write:int},server_process_io:array{linux_procfs:bool,read_syscalls_delta:int,write_syscalls_delta:int,rchar_delta:int,wchar_delta:int,scope:string},socket_io:array{request_size_bytes:int,write_calls:int,write_bytes:int,write_would_block:int,read_calls:int,read_bytes:int,write_bytes_per_request:float,read_bytes_per_request:float,write_calls_per_request:float,read_calls_per_request:float}}
 * @throws \InvalidArgumentException
 * @throws \RuntimeException
 */
function http1SustainedMain(array $argv): array
{
    $settings = http1SustainedArguments($argv);
    $port = $settings['port'];
    $concurrency = $settings['concurrency'];
    $warmup = $settings['warmup'];
    $duration = $settings['duration'];
    $serverPid = $settings['server_pid'];

    if ($warmup > 0) {
        $warm = http1SustainedRun('127.0.0.1', $port, $concurrency, $warmup, $serverPid, false);
        http1SustainedAssertCorrect($warm['counter'], 'Warm-up');
    }

    $measured = http1SustainedRun('127.0.0.1', $port, $concurrency, $duration, $serverPid, true);
    $counter = $measured['counter'];
    $failures = $counter['errors_total'] + $counter['timeouts_total'] + $counter['validation_failures'];
    $requests = $counter['requests_total'];
    $cpu = http1SustainedCpuPercent($measured);

    // Keep the requested contract separate from the measured wall time.
    // An early exit (e.g. every client disconnected) cannot be a valid soak.
    $continuousDurationPassed = $measured['elapsed'] >= $duration - 0.002;
    $result = [
        'runtime' => 'runwire-native',
        'runtime_version' => http1SustainedEnvironment('RUNWIRE_RUNTIME_VERSION', '2.0-candidate'),
        'runtime_build' => http1SustainedEnvironment('RUNWIRE_RUNTIME_BUILD', 'unknown'),
        'protocol' => 'http/1.1',
        'workload' => getenv('RUNWIRE_BENCH_TLS') === '1' ? 'verified-tls-H1' : 'plaintext-H1',
        'hardware_id' => php_uname('m') . '::' . http1SustainedCpuModel(),
        'host_os' => php_uname('a'),
        'host_cpu' => http1SustainedCpuModel(),
        'php_version' => http1SustainedEnvironment('RUNWIRE_PHP_VERSION', PHP_VERSION),
        'instrumentation' => http1SustainedEnvironment('RUNWIRE_INSTRUMENTATION', 'ci-smoke'),
        'tls' => getenv('RUNWIRE_BENCH_TLS') === '1' ? 'on-verified' : 'off',
        'opcache' => http1SustainedEnvironment('RUNWIRE_OPCACHE', 'unknown'),
        'connection_reuse' => http1BenchConnectionMode(),
        'response_body_bytes' => strlen(http1BenchResponseBody()),
        'extension_versions' => http1SustainedExtensionVersions(),
        'workers' => 1,
        'concurrency' => $concurrency,
        'duration_seconds' => $duration,
        'actual_duration_seconds' => round($measured['elapsed'], 3),
        'continuous_duration_passed' => $continuousDurationPassed,
        'requests_total' => $requests,
        'completed_requests' => $counter['completed_requests'],
        'successful_requests' => $counter['successful_requests'],
        'throughput_rps' => $measured['elapsed'] > 0 ? round((float) $counter['successful_requests'] / $measured['elapsed'], 3) : 0.0,
        'latency_ms' => [
            'p50' => http1SustainedPercentile($measured['histogram'], 0.50),
            'p95' => http1SustainedPercentile($measured['histogram'], 0.95),
            'p99' => http1SustainedPercentile($measured['histogram'], 0.99),
        ],
        'errors_total' => $counter['errors_total'],
        'error_breakdown' => [
            'connect' => $counter['connect_errors'],
            'response_read' => $counter['response_read_errors'],
            'write' => $counter['write_errors'],
        ],
        'timeouts_total' => $counter['timeouts_total'],
        'validation_failures' => $counter['validation_failures'],
        'reconnects_total' => $counter['reconnects_total'],
        'error_rate' => $requests > 0 ? round($failures / $requests, 8) : 1.0,
        'cpu_percent' => round($cpu, 3),
        'rss_peak_bytes' => $measured['peak_rss'],
        'server_process_io' => [
            'linux_procfs' => is_readable('/proc/' . $serverPid . '/io'),
            'read_syscalls_delta' => max(0, $measured['io_end']['syscr'] - $measured['io_start']['syscr']),
            'write_syscalls_delta' => max(0, $measured['io_end']['syscw'] - $measured['io_start']['syscw']),
            'rchar_delta' => max(0, $measured['io_end']['rchar'] - $measured['io_start']['rchar']),
            'wchar_delta' => max(0, $measured['io_end']['wchar'] - $measured['io_start']['wchar']),
            'scope' => 'whole-server-process-tree-not-socket-only',
        ],
        'socket_io' => http1SustainedSocketIo($counter, $requests),
        'correctness_passed' => $continuousDurationPassed
            && $requests > 0
            && $counter['completed_requests'] === $requests
            && $counter['successful_requests'] === $requests
            && $failures === 0,
    ];

    return $result;
}

$scriptFilename = $_SERVER['SCRIPT_FILENAME'] ?? null;
if (is_string($scriptFilename) && realpath($scriptFilename) === __FILE__) {
    if (!isset($argc, $argv)) {
        throw new \RuntimeException('CLI arguments are unavailable.');
    }
    $result = http1SustainedMain($argv);
    fwrite(STDOUT, json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
}
