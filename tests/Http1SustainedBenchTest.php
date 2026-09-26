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
file_put_contents($argv[1], substr(strrchr((string) $name, ':'), 1));
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
        ob_start();
        http1SustainedMain([
            'http1_sustained_bench.php',
            (string) $fixture['port'],
            '2',
            '0.05',
            '0.10',
            (string) getmypid(),
        ]);
        $output = ob_get_clean();
        $decoded = json_decode((string) $output, true, flags: JSON_THROW_ON_ERROR);
        expect($decoded['correctness_passed'])->toBeTrue()
            ->and($decoded['successful_requests'])->toBe($decoded['requests_total'])
            ->and($decoded['errors_total'])->toBe(0)
            ->and($decoded['timeouts_total'])->toBe(0)
            ->and($decoded['latency_ms']['p95'])->toBeGreaterThan(0);
    } finally {
        stopHttp1SustainedFixture($fixture);
    }
});
