<?php

declare(strict_types=1);

use Closure;
use RuntimeException;
use Throwable;

final class WebSocketEvidenceTimeout extends RuntimeException
{
}

final class WebSocketEvidenceProtocolFailure extends RuntimeException
{
}

/** @return resource */
function wsConnect(int $port): mixed
{
    $socket = stream_socket_client(
        'tcp://127.0.0.1:' . $port,
        $errno,
        $error,
        2.0,
        STREAM_CLIENT_CONNECT,
    );
    if (!is_resource($socket)) {
        throw new RuntimeException(sprintf('WebSocket connect failed: %s (%d).', $error, $errno));
    }

    stream_set_timeout($socket, 2);
    $key = base64_encode(random_bytes(16));
    $request = "GET /ws HTTP/1.1\r\n"
        . "Host: 127.0.0.1:" . $port . "\r\n"
        . "Upgrade: websocket\r\n"
        . "Connection: Upgrade\r\n"
        . "Sec-WebSocket-Version: 13\r\n"
        . "Sec-WebSocket-Key: " . $key . "\r\n"
        . "\r\n";

    wsWriteAll($socket, $request);
    $head = wsReadHead($socket);
    if (!str_starts_with($head, 'HTTP/1.1 101 ')) {
        fclose($socket);
        throw new WebSocketEvidenceProtocolFailure('Server rejected the WebSocket handshake.');
    }

    $expected = base64_encode(sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
    $headers = [];
    foreach (explode("\r\n", $head) as $line) {
        if (!str_contains($line, ':')) {
            continue;
        }
        [$name, $value] = explode(':', $line, 2);
        $headers[strtolower(trim($name))] = trim($value);
    }
    if (($headers['sec-websocket-accept'] ?? '') !== $expected) {
        fclose($socket);
        throw new WebSocketEvidenceProtocolFailure('Server returned an invalid Sec-WebSocket-Accept value.');
    }

    return $socket;
}

/** @param resource $socket */
function wsReadHead(mixed $socket): string
{
    $buffer = '';
    while (!str_contains($buffer, "\r\n\r\n")) {
        if (strlen($buffer) > 65_536) {
            throw new WebSocketEvidenceProtocolFailure('Handshake response exceeded 64 KiB.');
        }

        $chunk = fread($socket, 4_096);
        if ($chunk === false) {
            throw new RuntimeException('Unable to read WebSocket handshake.');
        }
        if ($chunk === '') {
            $metadata = stream_get_meta_data($socket);
            if (($metadata['timed_out'] ?? false) === true) {
                throw new WebSocketEvidenceTimeout('Timed out reading WebSocket handshake.');
            }
            if (feof($socket)) {
                throw new WebSocketEvidenceProtocolFailure('Peer closed during WebSocket handshake.');
            }

            continue;
        }
        $buffer .= $chunk;
    }

    [$head] = explode("\r\n\r\n", $buffer, 2);

    return $head . "\r\n\r\n";
}

/** @param resource $socket */
function wsReadExact(mixed $socket, int $bytes): string
{
    if ($bytes < 0) {
        throw new InvalidArgumentException('WebSocket read length cannot be negative.');
    }

    $buffer = '';
    while (strlen($buffer) < $bytes) {
        $chunk = fread($socket, max(1, $bytes - strlen($buffer)));
        if ($chunk === false) {
            throw new RuntimeException('Unable to read WebSocket frame.');
        }
        if ($chunk === '') {
            $metadata = stream_get_meta_data($socket);
            if (($metadata['timed_out'] ?? false) === true) {
                throw new WebSocketEvidenceTimeout('Timed out reading WebSocket frame.');
            }
            if (feof($socket)) {
                throw new WebSocketEvidenceProtocolFailure('Peer closed before the WebSocket frame completed.');
            }

            continue;
        }
        $buffer .= $chunk;
    }

    return $buffer;
}

/**
 * @param resource $socket
 * @return array{opcode: int, fin: bool, payload: string}
 */
function wsReadFrame(mixed $socket): array
{
    $head = wsReadExact($socket, 2);
    $first = ord($head[0]);
    $second = ord($head[1]);
    $fin = ($first & 0x80) !== 0;
    $opcode = $first & 0x0f;
    if (($first & 0x70) !== 0) {
        throw new WebSocketEvidenceProtocolFailure('Server set an unexpected RSV bit.');
    }
    if (($second & 0x80) !== 0) {
        throw new WebSocketEvidenceProtocolFailure('Server WebSocket frames must not be masked.');
    }

    $indicator = $second & 0x7f;
    if ($indicator < 126) {
        $length = $indicator;
    } elseif ($indicator === 126) {
        $extended = wsReadExact($socket, 2);
        $length = (ord($extended[0]) << 8) | ord($extended[1]);
        if ($length < 126) {
            throw new WebSocketEvidenceProtocolFailure('Server used a non-minimal 16-bit frame length.');
        }
    } else {
        $extended = wsReadExact($socket, 8);
        if (ord($extended[0]) !== 0 || ord($extended[1]) !== 0 || ord($extended[2]) !== 0 || ord($extended[3]) !== 0) {
            throw new WebSocketEvidenceProtocolFailure('Evidence client refuses frames above 32-bit length.');
        }
        $length = (ord($extended[4]) << 24)
            | (ord($extended[5]) << 16)
            | (ord($extended[6]) << 8)
            | ord($extended[7]);
        if ($length < 65_536) {
            throw new WebSocketEvidenceProtocolFailure('Server used a non-minimal 64-bit frame length.');
        }
    }
    if ($length > 1_048_576) {
        throw new WebSocketEvidenceProtocolFailure('Server frame exceeded the 1 MiB evidence ceiling.');
    }
    if ($opcode >= 0x8 && (!$fin || $length > 125)) {
        throw new WebSocketEvidenceProtocolFailure('Server emitted an invalid control frame.');
    }

    return [
        'opcode' => $opcode,
        'fin' => $fin,
        'payload' => wsReadExact($socket, $length),
    ];
}

/** @param resource $socket */
function wsWriteFrame(mixed $socket, int $opcode, string $payload): void
{
    if ($opcode < 0 || $opcode > 15) {
        throw new InvalidArgumentException('WebSocket opcode must be between 0 and 15.');
    }

    $length = strlen($payload);
    $mask = random_bytes(4);
    $first = chr(0x80 | $opcode);
    if ($length < 126) {
        $head = $first . chr(0x80 | $length);
    } elseif ($length <= 65_535) {
        $head = $first . chr(0x80 | 126) . pack('n', $length);
    } else {
        $head = $first . chr(0x80 | 127) . pack('NN', 0, $length);
    }

    $masked = $payload;
    for ($index = 0; $index < $length; ++$index) {
        $masked[$index] = $masked[$index] ^ $mask[$index & 3];
    }

    wsWriteAll($socket, $head . $mask . $masked);
}

/** @param resource $socket */
function wsWriteAll(mixed $socket, string $wire): void
{
    $offset = 0;
    $length = strlen($wire);
    while ($offset < $length) {
        $written = fwrite($socket, substr($wire, $offset));
        if ($written === false) {
            throw new RuntimeException('Unable to write WebSocket bytes.');
        }
        if ($written === 0) {
            $metadata = stream_get_meta_data($socket);
            if (($metadata['timed_out'] ?? false) === true) {
                throw new WebSocketEvidenceTimeout('Timed out writing WebSocket bytes.');
            }

            usleep(1_000);
            continue;
        }

        $offset += $written;
    }
}

/** @param resource $socket */
function wsAwaitDataFrame(mixed $socket, int $expectedOpcode): string
{
    while (true) {
        $frame = wsReadFrame($socket);
        if ($frame['opcode'] === 0x9) {
            wsWriteFrame($socket, 0xA, $frame['payload']);

            continue;
        }
        if ($frame['opcode'] === 0x8) {
            throw new WebSocketEvidenceProtocolFailure('Server closed before the expected data frame.');
        }
        if ($frame['opcode'] !== $expectedOpcode || !$frame['fin']) {
            throw new WebSocketEvidenceProtocolFailure('Unexpected WebSocket data frame.');
        }

        return $frame['payload'];
    }
}

/** @param resource $socket */
function wsPing(mixed $socket, string $payload): void
{
    wsWriteFrame($socket, 0x9, $payload);
    while (true) {
        $frame = wsReadFrame($socket);
        if ($frame['opcode'] === 0x9) {
            wsWriteFrame($socket, 0xA, $frame['payload']);

            continue;
        }
        if ($frame['opcode'] !== 0xA || $frame['payload'] !== $payload) {
            throw new WebSocketEvidenceProtocolFailure('Expected WebSocket pong was not received.');
        }

        return;
    }
}

/** @param resource $socket */
function wsClose(mixed $socket): void
{
    wsWriteFrame($socket, 0x8, pack('n', 1000));
    try {
        $frame = wsReadFrame($socket);
        if ($frame['opcode'] !== 0x8) {
            throw new WebSocketEvidenceProtocolFailure('Expected WebSocket close response.');
        }
    } catch (WebSocketEvidenceTimeout) {
        throw new WebSocketEvidenceProtocolFailure('Server did not complete the close handshake.');
    } finally {
        fclose($socket);
    }
}

/** @return array<string, int|float|bool|string> */
function wsWorker(int $port, float $duration, int $workerId): array
{
    $counters = [
        'messages' => 0,
        'successful_messages' => 0,
        'pings' => 0,
        'errors' => 0,
        'timeouts' => 0,
        'validation_failures' => 0,
    ];
    $latencies = [];
    $errorSample = '';
    $socket = null;

    try {
        $socket = wsConnect($port);
        $deadline = microtime(true) + $duration;
        $sequence = 0;

        while (microtime(true) < $deadline) {
            $binary = $sequence % 8 === 7;
            $payload = $binary
                ? chr($workerId & 0xff) . pack('N', $sequence) . str_repeat('x', 251)
                : sprintf('%d:%d:', $workerId, $sequence) . str_repeat('x', 247);
            $opcode = $binary ? 0x2 : 0x1;
            $started = hrtime(true);

            wsWriteFrame($socket, $opcode, $payload);
            ++$counters['messages'];
            $reply = wsAwaitDataFrame($socket, $opcode);
            if ($reply !== $payload) {
                ++$counters['validation_failures'];
                break;
            }

            ++$counters['successful_messages'];
            $latencies[] = (hrtime(true) - $started) / 1_000_000;
            ++$sequence;

            if ($sequence % 64 === 0) {
                wsPing($socket, 'rw');
                ++$counters['pings'];
            }
        }

        wsClose($socket);
        $socket = null;
    } catch (WebSocketEvidenceTimeout $error) {
        ++$counters['timeouts'];
        $errorSample = 'timeout: ' . $error->getMessage();
    } catch (Throwable $error) {
        ++$counters['errors'];
        $errorSample = $error::class . ': ' . $error->getMessage();
    } finally {
        if (is_resource($socket)) {
            fclose($socket);
        }
    }

    return [
        ...$counters,
        'latencies' => $latencies,
        'error_sample' => $errorSample,
    ];
}

/** @return ?array<string, mixed> Null is returned in forked child processes after writing their result. */
function wsTrial(int $port, int $concurrency, float $duration): ?array
{
    if (!function_exists('pcntl_fork')) {
        throw new RuntimeException('WebSocket evidence requires pcntl_fork().');
    }

    $children = [];
    for ($worker = 0; $worker < $concurrency; ++$worker) {
        $path = tempnam(sys_get_temp_dir(), 'runwire-ws-evidence-');
        if (!is_string($path)) {
            throw new RuntimeException('Unable to create WebSocket evidence result file.');
        }

        $pid = pcntl_fork();
        if ($pid === -1) {
            @unlink($path);
            throw new RuntimeException('Unable to fork WebSocket evidence worker.');
        }
        if ($pid === 0) {
            $result = wsWorker($port, $duration, $worker);
            file_put_contents($path, json_encode($result, JSON_THROW_ON_ERROR));

            return null;
        }

        $children[] = ['pid' => $pid, 'path' => $path];
    }

    $messages = $successful = $pings = $errors = $timeouts = $validationFailures = 0;
    $latencies = [];
    $samples = [];
    $started = microtime(true);

    foreach ($children as $child) {
        pcntl_waitpid($child['pid'], $status);
        $raw = file_get_contents($child['path']);
        @unlink($child['path']);
        if (!is_string($raw) || $raw === '') {
            ++$errors;
            $samples[] = 'worker returned no evidence';
            continue;
        }

        $result = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        $messages += (int) ($result['messages'] ?? 0);
        $successful += (int) ($result['successful_messages'] ?? 0);
        $pings += (int) ($result['pings'] ?? 0);
        $errors += (int) ($result['errors'] ?? 0);
        $timeouts += (int) ($result['timeouts'] ?? 0);
        $validationFailures += (int) ($result['validation_failures'] ?? 0);
        foreach (($result['latencies'] ?? []) as $latency) {
            $latencies[] = (float) $latency;
        }
        $sample = (string) ($result['error_sample'] ?? '');
        if ($sample !== '' && count($samples) < 10) {
            $samples[] = $sample;
        }
    }

    $elapsed = microtime(true) - $started;
    sort($latencies, SORT_NUMERIC);

    return [
        'messages' => $messages,
        'successful_messages' => $successful,
        'pings' => $pings,
        'errors' => $errors,
        'timeouts' => $timeouts,
        'validation_failures' => $validationFailures,
        'error_samples' => $samples,
        'duration_seconds' => round($elapsed, 6),
        'messages_per_second' => round($successful / max($elapsed, 0.000001), 3),
        'latency_ms' => [
            'p50' => round(wsPercentile($latencies, 0.50), 3),
            'p95' => round(wsPercentile($latencies, 0.95), 3),
            'p99' => round(wsPercentile($latencies, 0.99), 3),
        ],
        'correctness_passed' => $messages > 0
            && $successful === $messages
            && $errors === 0
            && $timeouts === 0
            && $validationFailures === 0,
    ];
}

/** @param list<float> $values */
function wsPercentile(array $values, float $fraction): float
{
    if ($values === []) {
        return 0.0;
    }

    $index = max(0, min(count($values) - 1, (int) ceil($fraction * count($values)) - 1));

    return $values[$index];
}

/** @param list<float> $values */
function wsMedian(array $values): float
{
    sort($values, SORT_NUMERIC);
    $count = count($values);
    if ($count === 0) {
        return 0.0;
    }

    $middle = intdiv($count, 2);

    return $count % 2 === 1
        ? $values[$middle]
        : ($values[$middle - 1] + $values[$middle]) / 2;
}

/** @param list<float> $values */
function wsCoefficientOfVariation(array $values): float
{
    $count = count($values);
    if ($count < 2) {
        return 0.0;
    }

    $mean = array_sum($values) / $count;
    if ($mean <= 0.0) {
        return 0.0;
    }

    $sum = 0.0;
    foreach ($values as $value) {
        $sum += ($value - $mean) ** 2;
    }

    return sqrt($sum / ($count - 1)) / $mean * 100.0;
}

function wsSlowReader(int $port): bool
{
    $socket = null;
    try {
        $socket = wsConnect($port);
        $payloads = [];
        for ($index = 0; $index < 32; ++$index) {
            $payload = chr($index & 0xff) . str_repeat('s', 32_767);
            $payloads[] = $payload;
            wsWriteFrame($socket, 0x2, $payload);
        }

        usleep(250_000);
        foreach ($payloads as $payload) {
            if (wsAwaitDataFrame($socket, 0x2) !== $payload) {
                return false;
            }
        }

        wsPing($socket, 'slow');
        wsClose($socket);
        $socket = null;

        return true;
    } catch (Throwable) {
        return false;
    } finally {
        if (is_resource($socket)) {
            fclose($socket);
        }
    }
}

function wsMain(array $arguments): void
{
    $options = getopt('', ['port:', 'concurrency::', 'duration::', 'trials::', 'soak-seconds::']);
    $port = (int) ($options['port'] ?? 0);
    $concurrency = (int) ($options['concurrency'] ?? 8);
    $duration = (float) ($options['duration'] ?? 3.0);
    $trials = (int) ($options['trials'] ?? 5);
    $soakSeconds = (float) ($options['soak-seconds'] ?? 0.0);

    if ($port < 1 || $port > 65_535) {
        throw new InvalidArgumentException('port must be between 1 and 65535');
    }
    if ($concurrency < 1 || $concurrency > 256) {
        throw new InvalidArgumentException('concurrency must be between 1 and 256');
    }
    if ($duration <= 0.0 || $trials < 1 || $soakSeconds < 0.0) {
        throw new InvalidArgumentException('duration/trials must be positive and soak-seconds non-negative');
    }

    $slowReaderPassed = wsSlowReader($port);
    $records = [];
    for ($trial = 0; $trial < $trials; ++$trial) {
        $record = wsTrial($port, $concurrency, $duration);
        if ($record === null) {
            return;
        }
        $records[] = $record;
    }

    $soak = null;
    if ($soakSeconds > 0.0) {
        $soak = wsTrial($port, $concurrency, $soakSeconds);
        if ($soak === null) {
            return;
        }
    }

    $rates = array_map(static fn(array $record): float => (float) $record['messages_per_second'], $records);
    $p95 = array_map(static fn(array $record): float => (float) $record['latency_ms']['p95'], $records);
    $p99 = array_map(static fn(array $record): float => (float) $record['latency_ms']['p99'], $records);
    $messages = array_sum(array_map(static fn(array $record): int => (int) $record['messages'], $records));
    $successful = array_sum(array_map(static fn(array $record): int => (int) $record['successful_messages'], $records));
    $pings = array_sum(array_map(static fn(array $record): int => (int) $record['pings'], $records));
    $errors = array_sum(array_map(static fn(array $record): int => (int) $record['errors'], $records));
    $timeouts = array_sum(array_map(static fn(array $record): int => (int) $record['timeouts'], $records));
    $validationFailures = array_sum(array_map(static fn(array $record): int => (int) $record['validation_failures'], $records));
    $samples = [];
    foreach ($records as $record) {
        foreach ($record['error_samples'] as $sample) {
            if (count($samples) < 10) {
                $samples[] = $sample;
            }
        }
    }

    $passed = $slowReaderPassed
        && $messages > 0
        && $successful === $messages
        && $errors === 0
        && $timeouts === 0
        && $validationFailures === 0
        && array_all($records, static fn(array $record): bool => $record['correctness_passed'] === true)
        && ($soak === null || $soak['correctness_passed'] === true);

    $result = [
        'client' => 'php-stdlib-rfc6455',
        'protocol' => 'rfc6455-http1',
        'compression' => 'disabled',
        'concurrency' => $concurrency,
        'trials' => $trials,
        'duration_seconds_per_trial' => $duration,
        'messages_total' => $messages,
        'successful_messages' => $successful,
        'pings_total' => $pings,
        'errors_total' => $errors,
        'error_samples' => $samples,
        'timeouts_total' => $timeouts,
        'validation_failures' => $validationFailures,
        'median_messages_per_second' => round(wsMedian($rates), 3),
        'rate_cv_percent' => round(wsCoefficientOfVariation($rates), 3),
        'median_p95_ms' => round(wsMedian($p95), 3),
        'median_p99_ms' => round(wsMedian($p99), 3),
        'slow_reader_passed' => $slowReaderPassed,
        'soak_seconds' => $soakSeconds,
        'soak_correctness_passed' => $soak === null ? null : $soak['correctness_passed'],
        'correctness_passed' => $passed,
    ];

    fwrite(
        STDOUT,
        json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL,
    );

    if (!$passed) {
        throw new RuntimeException('Native WebSocket PHP interoperability/soak evidence failed.');
    }
}

wsMain($argv);
