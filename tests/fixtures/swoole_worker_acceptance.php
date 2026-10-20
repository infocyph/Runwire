<?php

declare(strict_types=1);

// Exercise both public host APIs against real two-worker Swoole-family servers.
$check = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$request = static function (int $port, string $path = '/probe') use ($check): array {
    $stream = fopen('http://127.0.0.1:' . $port . $path, 'r', false, stream_context_create([
        'http' => ['timeout' => 3, 'ignore_errors' => true],
    ]));
    $check(is_resource($stream), 'HTTP request failed: ' . $path);
    try {
        $metadata = stream_get_meta_data($stream);
        $check(preg_match('/^HTTP\/\S+ 200\b/', $metadata['wrapper_data'][0] ?? '') === 1, 'Unexpected HTTP status.');
        $body = stream_get_contents($stream);
        $metadata = stream_get_meta_data($stream);
        $check(is_string($body) && !$metadata['timed_out'], 'Incomplete HTTP response.');
        $result = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        $check(is_array($result), 'Expected a JSON response object.');

        return $result;
    } finally {
        fclose($stream);
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

$directory = sys_get_temp_dir() . '/runwire-swoole-workers-' . bin2hex(random_bytes(8));
$check(mkdir($directory, 0700), 'Unable to create acceptance workspace.');
try {
    foreach (['factory', 'callback'] as $api) {
        $reservation = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
        $check(is_resource($reservation), 'Unable to reserve a port: ' . $errorMessage);
        $address = stream_socket_get_name($reservation, false);
        fclose($reservation);
        $check(is_string($address), 'Unable to resolve the reserved port.');
        $port = (int) substr($address, strrpos($address, ':') + 1);
        $trace = $directory . '/' . $api . '.jsonl';
        $log = $directory . '/' . $api . '.log';
        $server = proc_open([
            PHP_BINARY, __DIR__ . '/swoole_worker_lifecycle.php', (string) $port, $trace, $api,
        ], [0 => ['file', '/dev/null', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes);
        $check(is_resource($server), 'Unable to start the Swoole-family host.');
        $clients = [];
        try {
            $status = proc_get_status($server);
            $masterPid = $status['pid'];
            $deadline = microtime(true) + 20;
            while (true) {
                $check(proc_get_status($server)['running'], 'Host exited before readiness.');
                $check(microtime(true) < $deadline, 'Host did not become ready.');
                if (!is_file($trace) || substr_count((string) file_get_contents($trace), '"event":"boot"') < 2) {
                    usleep(50_000);
                    continue;
                }
                try {
                    $request($port);
                    break;
                } catch (RuntimeException $error) {
                    $check(microtime(true) < $deadline, 'Host did not become ready: ' . $error->getMessage());
                    usleep(50_000);
                }
            }
            for ($i = 0; $i < 8; $i++) {
                $client = proc_open([PHP_BINARY, __FILE__, 'client', (string) $port], [
                    0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $log, 'a'],
                ], $clientPipes);
                $check(is_resource($client), 'Unable to start an acceptance client.');
                $clients[] = [$client, $clientPipes[1]];
            }
            $responses = [];
            foreach ($clients as [$client, $output]) {
                $json = stream_get_contents($output);
                fclose($output);
                $check(proc_close($client) === 0, 'Acceptance client failed.');
                array_push($responses, ...json_decode($json, true, flags: JSON_THROW_ON_ERROR));
            }
            $workers = array_unique(array_column($responses, 'pid'));
            // Reactor scheduling need not distribute the initial burst evenly.
            // Keep a finite probe budget; fail if both workers cannot actually serve traffic.
            for ($probe = 0; $probe < 64 && count($workers) === 1; ++$probe) {
                usleep(20_000);
                $responses[] = $request($port);
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
            $deadline = microtime(true) + 10;
            do {
                $status = proc_get_status($server);
                $check(microtime(true) < $deadline, 'Host did not shut down cleanly.');
                if ($status['running']) {
                    usleep(50_000);
                }
            } while ($status['running']);
            $check($status['exitcode'] === 0, 'Host exited unsuccessfully.');
            $lines = file($trace, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            $check(is_array($lines), 'Worker lifecycle evidence is missing.');
            $events = array_map(static fn(string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR), $lines);
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
                $workerEvents = array_filter($events, static fn(array $event): bool => $event['pid'] === $pid);
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
            ) . PHP_EOL);
        } catch (Throwable $error) {
            fwrite(STDERR, (string) file_get_contents($log));
            if (is_file($trace)) {
                fwrite(STDERR, (string) file_get_contents($trace));
            }
            throw $error;
        } finally {
            foreach ($clients as [$client, $output]) {
                if (is_resource($output)) {
                    fclose($output);
                }
                if (is_resource($client)) {
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
    foreach (glob($directory . '/*') as $file) {
        unlink($file);
    }
    rmdir($directory);
}
