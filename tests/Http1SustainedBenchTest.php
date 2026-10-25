<?php

declare(strict_types=1);

require_once __DIR__ . '/../benchmarks/http1_sustained_bench.php';

/** @return array{process:resource, script:string, ready:string, stdout:string, stderr:string, port:int} */
function startHttp1SustainedFixture(string $response, bool $repeat): array
{
    $base = tempnam(sys_get_temp_dir(), 'runwire-http1-fixture-');
    if (!is_string($base)) {
        throw new RuntimeException('Unable to allocate fixture path.');
    }
    $script = $base . '.php';
    $ready = $base . '.ready';
    $stdout = $base . '.out';
    $stderr = $base . '.err';
    $source = <<<'PHP'
<?php
$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if (!is_resource($server)) {
    throw new RuntimeException($error);
}
$name = stream_socket_get_name($server, false);
if (!is_string($name) || !str_contains($name, ':')) {
    throw new RuntimeException('Fixture did not obtain a valid listening address.');
}
$port = (int) substr(strrchr($name, ':'), 1);
if ($port < 1 || $port > 65535) {
    throw new RuntimeException('Fixture did not obtain a valid listening port.');
}
// Atomic publication prevents reading the port file before the write is complete.
$pending = $argv[1] . '.pending';
if (file_put_contents($pending, (string) $port, LOCK_EX) === false || !rename($pending, $argv[1])) {
    throw new RuntimeException('Unable to publish the fixture listening port.');
}
$response = base64_decode($argv[2], true);
if (!is_string($response)) {
    throw new RuntimeException('Invalid fixture response.');
}
$repeat = $argv[3] === '1';
do {
    $client = stream_socket_accept($server, 2.0);
    if (!is_resource($client)) {
        continue;
    }
    $request = '';
    while (!str_contains($request, "\r\n\r\n")) {
        $chunk = fread($client, 8192);
        if ($chunk === false || $chunk === '') {
            break;
        }
        $request .= $chunk;
    }
    if ($request !== '') {
        fwrite($client, $response);
    }
    fclose($client);
} while ($repeat);
fclose($server);
PHP;
    file_put_contents($script, $source);
    $process = proc_open(
        [PHP_BINARY, $script, $ready, base64_encode($response), $repeat ? '1' : '0'],
        [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', $stdout, 'w'],
            2 => ['file', $stderr, 'w'],
        ],
        $pipes,
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start HTTP fixture.');
    }
    for ($attempt = 0; $attempt < 100 && !is_file($ready); ++$attempt) {
        usleep(20_000);
    }
    if (!is_file($ready)) {
        throw new RuntimeException('HTTP fixture did not become ready.');
    }

    return compact('process', 'script', 'ready', 'stdout', 'stderr') + ['port' => (int) file_get_contents($ready)];
}

/** @param array{process:resource, script:string, ready:string, stdout:string, stderr:string, port:int} $fixture */
function stopHttp1SustainedFixture(array $fixture): void
{
    $status = proc_get_status($fixture['process']);
    if ($status['running']) {
        proc_terminate($fixture['process']);
    }
    proc_close($fixture['process']);
    foreach (['script', 'ready', 'stdout', 'stderr'] as $key) {
        if (is_file($fixture[$key])) {
            unlink($fixture[$key]);
        }
    }
}

it('parses announced close and rejects invalid content lengths', function (): void {
    $head = "HTTP/1.1 200 OK\r\nContent-Length: 2\r\nConnection: keep-alive, cLoSe";
    expect(http1SustainedParseHead($head))->toBe([
        'status' => 200,
        'length' => 2,
        'close' => true,
    ]);

    expect(fn() => http1SustainedParseHead("HTTP/1.1 200 OK\r\nContent-Length: -1"))
        ->toThrow(Http1SustainedProtocolError::class);
    expect(fn() => http1SustainedParseHead("HTTP/1.1 200 OK\r\nContent-Length: 3\r\nContent-Length: 2"))
        ->toThrow(Http1SustainedProtocolError::class);
});

it('rotates announced close connections without phantom requests', function (): void {
    $fixture = startHttp1SustainedFixture(
        "HTTP/1.1 200 OK\r\nContent-Length: 2\r\nConnection: close\r\n\r\nok",
        true,
    );
    try {
        $result = http1SustainedRun('127.0.0.1', $fixture['port'], 2, 0.15, getmypid(), true);
        $counter = $result['counter'];
        expect($counter['requests_total'])->toBeGreaterThan(1)
            ->and($counter['completed_requests'])->toBe($counter['requests_total'])
            ->and($counter['successful_requests'])->toBe($counter['requests_total'])
            ->and($counter['errors_total'])->toBe(0)
            ->and($counter['timeouts_total'])->toBe(0)
            ->and($counter['reconnects_total'])->toBeGreaterThan(0);
    } finally {
        stopHttp1SustainedFixture($fixture);
    }
});

it('counts a dropped response body as a failed attempted request', function (): void {
    $fixture = startHttp1SustainedFixture(
        "HTTP/1.1 200 OK\r\nContent-Length: 2\r\n\r\n",
        false,
    );
    try {
        $result = http1SustainedRun('127.0.0.1', $fixture['port'], 1, 0.15, getmypid(), false);
        expect($result['counter'])->toMatchArray([
            'requests_total' => 1,
            'completed_requests' => 0,
            'successful_requests' => 0,
            'errors_total' => 1,
            'timeouts_total' => 0,
            'validation_failures' => 0,
            'reconnects_total' => 0,
        ]);
    } finally {
        stopHttp1SustainedFixture($fixture);
    }
});

it('produces correctness-passing CLI evidence through PHP only', function (): void {
    $fixture = startHttp1SustainedFixture(
        "HTTP/1.1 200 OK\r\nContent-Length: 2\r\nConnection: close\r\n\r\nok",
        true,
    );
    try {
        $result = http1SustainedMain([
            'http1_sustained_bench.php',
            (string) $fixture['port'],
            '2',
            '0.05',
            '0.10',
            (string) getmypid(),
        ]);
        expect($result['correctness_passed'])->toBeTrue()
            ->and($result['successful_requests'])->toBe($result['requests_total'])
            ->and($result['errors_total'])->toBe(0)
            ->and($result['timeouts_total'])->toBe(0)
            ->and($result['latency_ms']['p95'])->toBeGreaterThan(0);
    } finally {
        stopHttp1SustainedFixture($fixture);
    }
});


it('starts the strict HTTP/1.1 request deadline on first write, not while queued', function (): void {
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    expect($pair)->toBeArray();
    [$socket, $peer] = $pair;
    stream_set_blocking($socket, false);
    stream_set_blocking($peer, false);
    $client = ['stream' => $socket, 'active' => true];
    $counter = http1SustainedCounters();
    $request = "GET /benchmark HTTP/1.1\r\nHost: localhost\r\n\r\n";

    try {
        http1SustainedPrepareRequest($client, $counter);
        $admittedAt = microtime(true);
        expect($client['request_deadline'])->toBe(0.0)
            ->and($client['request_started'])->toBe(0);

        usleep(20_000);
        $clients = [0 => $client];
        $read = http1SustainedSelectSets($clients, $counter, strlen($request));
        expect($read['write'])->toHaveCount(1)
            ->and($counter['timeouts_total'])->toBe(0);

        http1SustainedWriteReady([$socket], [(int) $socket => 0], $clients, $counter, $request);
        $armedAt = microtime(true);
        expect($clients[0]['request_deadline'])->toBeGreaterThan($admittedAt + 2.0)
            ->and($clients[0]['request_deadline'])->toBeLessThanOrEqual($armedAt + 2.0)
            ->and($clients[0]['request_started'])->toBeGreaterThan(0)
            ->and($clients[0]['write_offset'])->toBe(strlen($request))
            ->and(fread($peer, 8192))->toBe($request);
    } finally {
        fclose($socket);
        fclose($peer);
    }
});

it('reports detailed strict request counters on benchmark correctness failure', function (): void {
    $counter = http1SustainedCounters();
    $counter['requests_total'] = 3;
    $counter['completed_requests'] = 2;
    $counter['successful_requests'] = 2;
    $counter['timeouts_total'] = 1;

    expect(fn() => http1SustainedAssertCorrect($counter, 'H1 stressed phase'))
        ->toThrow(RuntimeException::class, 'H1 stressed phase received a failed')
        ->and(fn() => http1SustainedAssertCorrect($counter, 'H1 stressed phase'))
        ->toThrow(RuntimeException::class, '"timeouts_total":1');
});

it('treats transient zero-byte nonblocking writes as bounded retry rather than a failed request', function (): void {
    $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    expect($sockets)->toBeArray();
    [$socket, $peer] = $sockets;
    stream_set_blocking($socket, false);
    stream_set_blocking($peer, false);
    stream_set_write_buffer($socket, 0);
    $filled = false;
    $chunk = str_repeat('z', 8192);
    try {
        for ($attempt = 0; $attempt < 2048; ++$attempt) {
            $written = fwrite($socket, $chunk);
            if ($written === 0) {
                $filled = true;

                break;
            }
            if ($written === false) {
                break;
            }
        }
        expect($filled)->toBeTrue('The local socket must expose real nonblocking send backpressure.');

        $clients = [0 => ['stream' => $socket, 'active' => true]];
        $counter = http1SustainedCounters();
        http1SustainedPrepareRequest($clients[0], $counter);
        $request = "GET /benchmark HTTP/1.1\\r\\nHost: localhost\\r\\n\\r\\n";
        http1SustainedWriteReady([$socket], [(int) $socket => 0], $clients, $counter, $request);
        expect($counter['errors_total'])->toBe(0)
            ->and($counter['write_errors'])->toBe(0)
            ->and($counter['write_would_block'])->toBe(1)
            ->and($clients[0]['active'])->toBeTrue()
            ->and($clients[0]['write_offset'])->toBe(0)
            ->and($clients[0]['request_deadline'])->toBeGreaterThan(microtime(true));
        $received = fread($peer, 8192);
        expect($received)->not->toBe('');
    } finally {
        fclose($socket);
        fclose($peer);
    }
});

it('records transport error classes without suppressing strict sustained request accounting', function (): void {
    $counters = http1SustainedCounters();
    expect($counters)->toHaveKeys([
        'connect_errors', 'response_read_errors', 'write_errors', 'write_would_block',
    ]);
    $counters['requests_total'] = 1;
    $counters['errors_total'] = 1;
    $counters['write_errors'] = 1;
    expect(fn() => http1SustainedAssertCorrect($counters, 'Backpressure diagnostics'))
        ->toThrow(RuntimeException::class, '"write_errors":1');
});
