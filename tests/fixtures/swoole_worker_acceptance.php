<?php

declare(strict_types=1);

/**
 * @return array{pid:int,runtime_pid:int,worker_slot:int,request_id:string}
 *
 * @throws RuntimeException
 */
function swooleWorkerResponse(mixed $value): array
{
    if (! is_array($value) || ! is_int($value['pid'] ?? null)
        || ! is_int($value['runtime_pid'] ?? null) || ! is_int($value['worker_slot'] ?? null)
        || ! is_string($value['request_id'] ?? null)) {
        throw new RuntimeException('Worker response has an invalid identity schema.');
    }

    return ['pid' => $value['pid'], 'runtime_pid' => $value['runtime_pid'],
        'worker_slot' => $value['worker_slot'], 'request_id' => $value['request_id']];
}

/**
 * @return array{pid:int,runtime_pid:int,event:string}
 *
 * @throws RuntimeException
 * @throws JsonException
 */
function swooleWorkerEvent(string $line): array
{
    $value = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
    if (! is_array($value) || ! is_int($value['pid'] ?? null)
        || ! is_int($value['runtime_pid'] ?? null) || ! is_string($value['event'] ?? null)) {
        throw new RuntimeException('Worker lifecycle record has an invalid identity schema.');
    }

    return ['pid' => $value['pid'], 'runtime_pid' => $value['runtime_pid'], 'event' => $value['event']];
}

/** @return list<array{pid:int,runtime_pid:int,worker_slot:int,request_id:string}>
 * @throws RuntimeException
 */
function swooleWorkerResponses(mixed $values): array
{
    if (! is_array($values) || ! array_is_list($values)) {
        throw new RuntimeException('Acceptance client output must contain a JSON response list.');
    }

    return array_map(swooleWorkerResponse(...), $values);
}

// Exercise both public host APIs against real two-worker Swoole-family servers.
$check = static function (bool $condition, string $message): void {
    if ($condition) {
        return;
    }
    throw new RuntimeException($message);
};
/** @return array{pid:int,runtime_pid:int,worker_slot:int,request_id:string} */
$request = static function (int $port, string $path = '/probe') use ($check): array {
    $stream = fopen('http://127.0.0.1:'.$port.$path, 'r', false, stream_context_create([
        'http' => ['timeout' => 3, 'ignore_errors' => true],
    ]));
    if (! is_resource($stream)) {
        throw new RuntimeException('HTTP request failed: '.$path);
    }
    try {
        $metadata = stream_get_meta_data($stream);
        $headers = $metadata['wrapper_data'] ?? null;
        $status = is_array($headers) ? ($headers[0] ?? null) : null;
        $check(is_string($status) && preg_match('/^HTTP\/\S+ 200\b/', $status) === 1, 'Unexpected HTTP status.');
        $body = stream_get_contents($stream);
        $metadata = stream_get_meta_data($stream);
        if (! is_string($body) || $metadata['timed_out']) {
            throw new RuntimeException('Incomplete HTTP response.');
        }
        $result = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($result)) {
            throw new RuntimeException('Expected a JSON response object.');
        }

        return swooleWorkerResponse($result);
    } finally {
        fclose($stream);
    }
};

// Keep simultaneous connections open until every request is submitted. The default
// Swoole-family fixed-FD dispatch may otherwise recycle one descriptor and route
// a burst of sequential localhost requests to only one of two healthy workers.
/** @return list<array{pid:int,runtime_pid:int,worker_slot:int,request_id:string}> */
$parallelRequests = static function (int $port, int $count) use ($check): array {
    $sockets = [];
    $results = [];
    $requestBytes = "GET /probe HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n";
    try {
        for ($index = 0; $index < $count; $index++) {
            $socket = stream_socket_client('tcp://127.0.0.1:'.$port, $errorCode, $error, 3.0);
            if (! is_resource($socket)) {
                throw new RuntimeException('Unable to create concurrent worker probe: '.$error);
            }
            stream_set_timeout($socket, 5);
            $check(fwrite($socket, $requestBytes) === strlen($requestBytes), 'Incomplete concurrent worker probe request.');
            $sockets[] = $socket;
        }

        foreach ($sockets as $socket) {
            $response = stream_get_contents($socket);
            if (! is_string($response)) {
                throw new RuntimeException('Unable to read concurrent worker probe response.');
            }
            $boundary = strpos($response, "\r\n\r\n");
            if ($boundary === false) {
                throw new RuntimeException('Concurrent worker probe returned incomplete HTTP headers.');
            }
            $status = substr($response, 0, $boundary);
            $check(preg_match('/^HTTP\/\S+ 200\b/', $status) === 1, 'Concurrent worker probe returned a non-200 status.');
            $body = substr($response, $boundary + 4);
            if (preg_match('/(?:^|\r\n)transfer-encoding:\s*chunked(?:\r\n|$)/i', $status) === 1) {
                $cursor = 0;
                $decodedChunks = [];
                while (true) {
                    $lineEnd = strpos($body, "\r\n", $cursor);
                    if ($lineEnd === false) {
                        throw new RuntimeException('Truncated chunked worker response header.');
                    }
                    $sizeLine = explode(';', substr($body, $cursor, $lineEnd - $cursor), 2)[0];
                    $check($sizeLine !== '' && ctype_xdigit($sizeLine), 'Invalid chunk size from worker.');
                    $size = hexdec($sizeLine);
                    if (! is_int($size)) {
                        throw new RuntimeException('Chunk size exceeds the platform integer range.');
                    }
                    $cursor = $lineEnd + 2;
                    if ($size === 0) {
                        break;
                    }
                    $check(
                        $cursor + $size + 2 <= strlen($body)
                            && substr($body, $cursor + $size, 2) === "\r\n",
                        'Truncated chunked worker response payload.',
                    );
                    $decodedChunks[] = substr($body, $cursor, $size);
                    $cursor += $size + 2;
                }
                $body = implode('', $decodedChunks);
            }
            try {
                $decoded = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
            } catch (JsonException $error) {
                throw new RuntimeException(
                    'Invalid multiworker response JSON (headers='.bin2hex(substr($status, 0, 192))
                    .', body='.bin2hex(substr($body, 0, 192)).').',
                    previous: $error,
                );
            }
            $check(is_array($decoded), 'Concurrent worker probe returned invalid JSON.');
            $results[] = swooleWorkerResponse($decoded);
        }

        return $results;
    } finally {
        foreach ($sockets as $socket) {
            fclose($socket);
        }
    }
};

if (($argv[1] ?? '') === 'client') {
    $responses = [];
    for ($i = 0; $i < 8; $i++) {
        $responses[] = $request((int) $argv[2]);
    }
    fwrite(STDOUT, json_encode($responses, JSON_THROW_ON_ERROR));
    exit(0);
}

$directory = sys_get_temp_dir().'/runwire-swoole-workers-'.bin2hex(random_bytes(8));
$check(mkdir($directory, 0700), 'Unable to create acceptance workspace.');
try {
    foreach (['factory', 'callback'] as $api) {
        $reservation = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
        if (! is_resource($reservation)) {
            throw new RuntimeException('Unable to reserve a port: '.$errorMessage);
        }
        $address = stream_socket_get_name($reservation, false);
        fclose($reservation);
        if (! is_string($address) || ($separator = strrpos($address, ':')) === false) {
            throw new RuntimeException('Unable to resolve the reserved port.');
        }
        $port = (int) substr($address, $separator + 1);
        $trace = $directory.'/'.$api.'.jsonl';
        $log = $directory.'/'.$api.'.log';
        $server = proc_open([
            PHP_BINARY, __DIR__.'/swoole_worker_lifecycle.php', (string) $port, $trace, $api,
        ], [0 => ['file', '/dev/null', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes);
        if (! is_resource($server)) {
            throw new RuntimeException('Unable to start the Swoole-family host.');
        }
        $clients = [];
        $clientPipes = [];
        try {
            $status = proc_get_status($server);
            $masterPid = $status['pid'];
            $deadline = microtime(true) + 20.0;
            while (true) {
                $check(proc_get_status($server)['running'], 'Host exited before readiness.');
                $check(microtime(true) < $deadline, 'Host did not become ready.');
                if (! is_file($trace) || substr_count((string) file_get_contents($trace), '"event":"boot"') < 2) {
                    usleep(50_000);

                    continue;
                }
                try {
                    $request($port);
                    break;
                } catch (RuntimeException $error) {
                    $check(microtime(true) < $deadline, 'Host did not become ready: '.$error->getMessage());
                    usleep(50_000);
                }
            }
            for ($i = 0; $i < 8; $i++) {
                $client = proc_open([PHP_BINARY, __FILE__, 'client', (string) $port], [
                    0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $log, 'a'],
                ], $clientPipes);
                if (! is_resource($client)) {
                    throw new RuntimeException('Unable to start an acceptance client.');
                }
                $clients[] = [$client, $clientPipes[1]];
            }
            $responses = [];
            foreach ($clients as [$client, $output]) {
                $json = stream_get_contents($output);
                fclose($output);
                $check(proc_close($client) === 0, 'Acceptance client failed.');
                if (! is_string($json)) {
                    throw new RuntimeException('Unable to read acceptance client output.');
                }
                $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
                if (! is_array($decoded) || ! array_is_list($decoded)) {
                    throw new RuntimeException('Acceptance client output must contain a JSON response list.');
                }
                array_push($responses, ...swooleWorkerResponses($decoded));
            }
            $workers = array_unique(array_column($responses, 'pid'));
            // Reactor scheduling need not distribute the initial burst evenly.
            // Keep a finite probe budget; fail if both workers cannot actually serve traffic.
            for ($probe = 0; $probe < 4 && count($workers) === 1; $probe++) {
                array_push($responses, ...$parallelRequests($port, 16));
                $workers = array_unique(array_column($responses, 'pid'));
            }
            $slots = array_unique(array_column($responses, 'worker_slot'));
            sort($slots);
            $check(
                count($responses) >= 64 && count($responses) <= 128 && count($workers) === 2,
                sprintf(
                    'Expected two active workers; observed %d responses from %d workers (PIDs: %s).',
                    count($responses),
                    count($workers),
                    implode(', ', $workers),
                ),
            );
            $check($slots === [0, 1], 'Expected both native worker slots.');
            $check(
                count(array_unique(array_column($responses, 'request_id'))) === count($responses),
                'Request identities leaked across requests.',
            );
            foreach ($responses as $response) {
                $check($response['runtime_pid'] === $response['pid'] && $response['pid'] !== $masterPid, 'Request inherited its parent context.');
            }
            if ($api === 'callback') {
                $request($port, '/stop');
            } else {
                proc_terminate($server);
            }
            $deadline = microtime(true) + 10.0;
            do {
                $status = proc_get_status($server);
                $check(microtime(true) < $deadline, 'Host did not shut down cleanly.');
                if ($status['running']) {
                    usleep(50_000);
                }
            } while ($status['running']);
            $check($status['exitcode'] === 0, 'Host exited unsuccessfully.');
            $lines = file($trace, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if (! is_array($lines)) {
                throw new RuntimeException('Worker lifecycle evidence is missing.');
            }
            $events = array_map(swooleWorkerEvent(...), $lines);
            $eventPids = array_unique(array_column($events, 'pid'));
            sort($eventPids);
            sort($workers);
            $check($eventPids === $workers, 'Application lifecycle ran outside the actual workers.');
            $expected = ['boot' => 1, 'drain' => 1, 'shutdown' => 1];
            if ($api === 'factory') {
                $expected['create'] = 1;
            }
            ksort($expected);
            foreach ($workers as $pid) {
                $workerEvents = array_filter($events, static fn (array $event): bool => $event['pid'] === $pid);
                $counts = array_count_values(array_column($workerEvents, 'event'));
                ksort($counts);
                $check($counts === $expected, 'Worker lifecycle did not complete exactly once.');
            }
            foreach ($events as $event) {
                $check($event['pid'] === $event['runtime_pid'], 'Lifecycle inherited its parent context.');
            }
            fwrite(STDOUT, sprintf(
                '%s: 2 workers, %d isolated requests, exact worker contexts and one shutdown each',
                $api,
                count($responses),
            ).PHP_EOL);
        } catch (Throwable $error) {
            fwrite(STDERR, (string) file_get_contents($log));
            if (is_file($trace)) {
                fwrite(STDERR, (string) file_get_contents($trace));
            }
            throw $error;
        } finally {
            foreach ($clients as [$client, $output]) {
                if (get_resource_type($output) !== 'Unknown') {
                    fclose($output);
                }
                if (get_resource_type($client) !== 'Unknown') {
                    proc_terminate($client);
                    proc_close($client);
                }
            }
            if (proc_get_status($server)['running']) {
                proc_terminate($server, 9);
            }
            proc_close($server);
        }
    }
} finally {
    $files = glob($directory.'/*');
    if (is_array($files)) {
        foreach ($files as $file) {
            unlink($file);
        }
    }
    rmdir($directory);
}
