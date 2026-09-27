<?php

declare(strict_types=1);

/** @param list<float> $values */
function rawTransportPercentile(array $values, float $fraction): float
{
    sort($values, SORT_NUMERIC);
    if ($values === []) {
        return 0.0;
    }

    $index = max(0, min(count($values) - 1, (int) ceil(count($values) * $fraction) - 1));

    return round($values[$index], 3);
}

/** @param resource $stream */
function rawTransportReadExact(mixed $stream, int $bytes): string
{
    $buffer = '';
    while (strlen($buffer) < $bytes) {
        $chunk = fread($stream, $bytes - strlen($buffer));
        if (!is_string($chunk) || $chunk === '') {
            throw new RuntimeException('Raw transport peer closed before the expected bytes arrived.');
        }
        $buffer .= $chunk;
    }

    return $buffer;
}

/** @param resource $listener */
function rawTransportServe(mixed $listener, int $iterations, bool $split): void
{
    $connection = stream_socket_accept($listener, 5.0);
    if (!is_resource($connection)) {
        throw new RuntimeException('Raw transport server did not accept the client.');
    }

    for ($iteration = 0; $iteration < $iterations; ++$iteration) {
        if (rawTransportReadExact($connection, 1) !== 'x') {
            throw new RuntimeException('Raw transport server received an invalid request byte.');
        }

        if ($split) {
            fwrite($connection, 'A');
            fwrite($connection, 'B');
        } else {
            fwrite($connection, 'AB');
        }
    }

    fclose($connection);
}

/** @return array<string, int|float|bool> */
function rawTransportRun(int $iterations, bool $tcpNoDelay, bool $split): array
{
    $context = stream_context_create(
        $tcpNoDelay ? ['socket' => ['tcp_nodelay' => true]] : [],
    );
    $errno = 0;
    $error = '';
    $listener = stream_socket_server(
        'tcp://127.0.0.1:0',
        $errno,
        $error,
        STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
        $context,
    );
    if (!is_resource($listener)) {
        throw new RuntimeException($error !== '' ? $error : 'Unable to create raw transport listener.');
    }

    $name = stream_socket_get_name($listener, false);
    if (!is_string($name) || !str_contains($name, ':')) {
        throw new RuntimeException('Unable to resolve raw transport listener address.');
    }

    $pid = pcntl_fork();
    if ($pid < 0) {
        throw new RuntimeException('Unable to fork raw transport benchmark server.');
    }
    if ($pid === 0) {
        rawTransportServe($listener, $iterations, $split);
        fclose($listener);

        return [];
    }

    $client = stream_socket_client('tcp://' . $name, $errno, $error, 5.0);
    if (!is_resource($client)) {
        throw new RuntimeException($error !== '' ? $error : 'Unable to connect raw transport client.');
    }
    fclose($listener);

    $latencies = [];
    $started = hrtime(true);
    for ($iteration = 0; $iteration < $iterations; ++$iteration) {
        $requestStarted = hrtime(true);
        fwrite($client, 'x');
        if (rawTransportReadExact($client, 2) !== 'AB') {
            throw new RuntimeException('Raw transport client received an invalid response.');
        }
        $latencies[] = (hrtime(true) - $requestStarted) / 1_000_000;
    }
    $elapsed = (hrtime(true) - $started) / 1_000_000_000;

    fclose($client);
    pcntl_waitpid($pid, $status);
    if (!pcntl_wifexited($status) || pcntl_wexitstatus($status) !== 0) {
        throw new RuntimeException('Raw transport benchmark server exited unsuccessfully.');
    }

    return [
        'iterations' => $iterations,
        'tcp_nodelay' => $tcpNoDelay,
        'split_writes' => $split,
        'throughput_rps' => round($iterations / $elapsed, 3),
        'p50_ms' => rawTransportPercentile($latencies, 0.50),
        'p95_ms' => rawTransportPercentile($latencies, 0.95),
        'p99_ms' => rawTransportPercentile($latencies, 0.99),
        'correctness_passed' => true,
    ];
}

if (count($argv) !== 4) {
    throw new InvalidArgumentException('Usage: php raw_transport_probe.php <iterations> <tcp-nodelay:0|1> <split-writes:0|1>');
}

$result = rawTransportRun((int) $argv[1], $argv[2] === '1', $argv[3] === '1');
if ($result !== []) {
    fwrite(STDOUT, json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
}
