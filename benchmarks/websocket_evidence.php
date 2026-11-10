<?php

declare(strict_types=1);

final class WebSocketEvidenceProtocolFailure extends RuntimeException {}

final class WebSocketEvidenceTimeout extends RuntimeException {}

/**
 * @return resource
 * @throws \RuntimeException
 * @throws \WebSocketEvidenceProtocolFailure
 * @throws \WebSocketEvidenceTimeout
 */
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
        . 'Host: 127.0.0.1:' . $port . "\r\n"
        . "Upgrade: websocket\r\n"
        . "Connection: Upgrade\r\n"
        . "Sec-WebSocket-Version: 13\r\n"
        . 'Sec-WebSocket-Key: ' . $key . "\r\n"
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
        $parts = explode(':', $line, 2);
        if (count($parts) !== 2) {
            continue;
        }
        [$name, $value] = $parts;
        $headers[strtolower(trim($name))] = trim($value);
    }
    if (($headers['sec-websocket-accept'] ?? '') !== $expected) {
        fclose($socket);

        throw new WebSocketEvidenceProtocolFailure('Server returned an invalid Sec-WebSocket-Accept value.');
    }

    return $socket;
}

/**
 * @param resource $socket
 * @throws WebSocketEvidenceTimeout
 * @throws WebSocketEvidenceProtocolFailure
 */
function wsCheckEmptyRead(mixed $socket, string $timeout, string $closed): void
{
    $metadata = stream_get_meta_data($socket);
    if ($metadata['timed_out']) {
        throw new WebSocketEvidenceTimeout($timeout);
    }
    if (feof($socket)) {
        throw new WebSocketEvidenceProtocolFailure($closed);
    }
}

/**
 * @param resource $socket
 * @throws \WebSocketEvidenceTimeout
 * @throws \RuntimeException
 */
function wsReadHead(mixed $socket): string
{
    $buffer = '';
    while (!str_contains($buffer, "\r\n\r\n")) {
        if (strlen($buffer) > 65_536) {
            throw new WebSocketEvidenceProtocolFailure('Handshake response exceeded 64 KiB.');
        }

        $chunk = fread($socket, 1);
        if ($chunk === false) {
            throw new RuntimeException('Unable to read WebSocket handshake.');
        }
        if ($chunk === '') {
            wsCheckEmptyRead($socket, 'Timed out reading WebSocket handshake.', 'Peer closed during WebSocket handshake.');

            continue;
        }
        $buffer .= $chunk;
    }

    [$head] = explode("\r\n\r\n", $buffer, 2);

    return $head . "\r\n\r\n";
}

/**
 * @param resource $socket
 * @throws \InvalidArgumentException
 * @throws \WebSocketEvidenceTimeout
 * @throws \RuntimeException
 */
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
            wsCheckEmptyRead($socket, 'Timed out reading WebSocket frame.', 'Peer closed before the WebSocket frame completed.');

            continue;
        }
        $buffer .= $chunk;
    }

    return $buffer;
}

/**
 * @param resource $socket
 * @throws RuntimeException
 * @throws WebSocketEvidenceTimeout
 * @throws WebSocketEvidenceProtocolFailure
   * @throws \InvalidArgumentException
 */
function wsFrameLength(mixed $socket, int $indicator): int
{
    if ($indicator < 126) {
        return $indicator;
    }
    if ($indicator === 126) {
        $extended = wsReadExact($socket, 2);
        $length = (ord($extended[0]) << 8) | ord($extended[1]);
        if ($length < 126) {
            throw new WebSocketEvidenceProtocolFailure('Server used a non-minimal 16-bit frame length.');
        }

        return $length;
    }
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

    return $length;
}

/**
 * @param resource $socket
 * @return array{opcode: int, fin: bool, payload: string}
 * @throws \WebSocketEvidenceProtocolFailure
 * @throws \InvalidArgumentException
 * @throws \WebSocketEvidenceTimeout
 * @throws \RuntimeException
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
    $length = wsFrameLength($socket, $indicator);
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

/**
 * @param resource $socket
 * @throws \InvalidArgumentException
 * @throws \WebSocketEvidenceTimeout
 * @throws \RuntimeException
 */
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

/**
 * @param resource $socket
 * @throws \WebSocketEvidenceTimeout
 * @throws \RuntimeException
 */
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
            if ($metadata['timed_out'] === true) {
                throw new WebSocketEvidenceTimeout('Timed out writing WebSocket bytes.');
            }

            usleep(1_000);

            continue;
        }

        $offset += $written;
    }
}

/**
 * @param resource $socket
 * @throws \WebSocketEvidenceTimeout
 * @throws \RuntimeException
 * @throws \InvalidArgumentException
 */
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

/**
 * @param resource $socket
 * @throws \InvalidArgumentException
 * @throws \WebSocketEvidenceTimeout
 * @throws \RuntimeException
 */
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

/**
 * @param resource $socket
 * @throws \WebSocketEvidenceProtocolFailure
 * @throws \InvalidArgumentException
   * @throws \WebSocketEvidenceTimeout
   * @throws \RuntimeException
 */
function wsClose(mixed $socket): void
{
    wsWriteFrame($socket, 0x8, pack('n', 1000));

    try {
        while (true) {
            $frame = wsReadFrame($socket);
            if ($frame['opcode'] === 0x9) {
                wsWriteFrame($socket, 0xA, $frame['payload']);

                continue;
            }
            if ($frame['opcode'] !== 0x8) {
                throw new WebSocketEvidenceProtocolFailure('Expected WebSocket close response.');
            }

            break;
        }
    } catch (WebSocketEvidenceTimeout) {
        throw new WebSocketEvidenceProtocolFailure('Server did not complete the close handshake.');
    } finally {
        fclose($socket);
    }
}

/**
 * @return array{
 *   messages: int,
 *   successful_messages: int,
 *   pings: int,
 *   errors: int,
 *   timeouts: int,
 *   validation_failures: int,
 *   latencies: list<float>,
 *   error_sample: string
 * }
 */
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
    $owner = new class {
        /** @var resource|closed-resource|null */
        public mixed $socket = null;
    };

    try {
        $socket = wsConnect($port);
        $owner->socket = $socket;
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
        $owner->socket = null;
    } catch (WebSocketEvidenceTimeout $error) {
        ++$counters['timeouts'];
        $errorSample = 'timeout: ' . $error->getMessage();
    } catch (Throwable $error) {
        ++$counters['errors'];
        $errorSample = $error::class . ': ' . $error->getMessage();
    } finally {
        wsReleaseSocket($owner->socket);
    }

    return [
        ...$counters,
        'latencies' => $latencies,
        'error_sample' => $errorSample,
    ];
}

/**
 * @param array<array-key,mixed> $record
 * @throws WebSocketEvidenceProtocolFailure
 */
function wsCounter(array $record, string $name): int
{
    $value = $record[$name] ?? null;
    if (!is_int($value) || $value < 0) {
        throw new WebSocketEvidenceProtocolFailure('Worker requires a non-negative integer counter: ' . $name);
    }

    return $value;
}

/**
 * @return list<float>
 * @throws WebSocketEvidenceProtocolFailure
 */
function wsLatencies(mixed $value): array
{
    if (!is_array($value) || !array_is_list($value)) {
        throw new WebSocketEvidenceProtocolFailure('Worker latency samples must be a list.');
    }
    $latencies = [];
    foreach ($value as $sample) {
        if ((!is_int($sample) && !is_float($sample)) || !is_finite((float) $sample) || $sample < 0) {
            throw new WebSocketEvidenceProtocolFailure('Worker latency samples must be finite non-negative numbers.');
        }
        $latencies[] = (float) $sample;
    }

    return $latencies;
}

/**
 * @return array{messages:int,successful_messages:int,pings:int,errors:int,timeouts:int,validation_failures:int,latencies:list<float>,error_sample:string}
 * @throws WebSocketEvidenceProtocolFailure
 */
function wsWorkerResult(mixed $value): array
{
    $errorSample = is_array($value) ? ($value['error_sample'] ?? null) : null;
    if (!is_array($value) || !is_string($errorSample)) {
        throw new WebSocketEvidenceProtocolFailure('Worker returned an incomplete evidence object.');
    }
    $result = [
        'messages' => wsCounter($value, 'messages'),
        'successful_messages' => wsCounter($value, 'successful_messages'),
        'pings' => wsCounter($value, 'pings'),
        'errors' => wsCounter($value, 'errors'),
        'timeouts' => wsCounter($value, 'timeouts'),
        'validation_failures' => wsCounter($value, 'validation_failures'),
        'latencies' => wsLatencies($value['latencies'] ?? null),
        'error_sample' => $errorSample;
    ];
    if ($result['successful_messages'] > $result['messages']
        || count($result['latencies']) !== $result['successful_messages']) {
        throw new WebSocketEvidenceProtocolFailure('Worker message counters and latency samples disagree.');
    }

    return $result;
}

/** @return array{messages:int,successful_messages:int,pings:int,errors:int,timeouts:int,validation_failures:int,latencies:list<float>,error_sample:string} */
function wsFailedWorker(string $message): array
{
    return [
        'messages' => 0, 'successful_messages' => 0, 'pings' => 0,
        'errors' => 1, 'timeouts' => 0, 'validation_failures' => 0,
        'latencies' => [], 'error_sample' => $message,
    ];
}

/** @return array{messages:int,successful_messages:int,pings:int,errors:int,timeouts:int,validation_failures:int,latencies:list<float>,error_sample:string} */
function wsChildRecord(mixed $status, mixed $raw): array
{
    if (!is_int($status) || !pcntl_wifexited($status) || pcntl_wexitstatus($status) !== 0
        || !is_string($raw) || $raw === '') {
        return wsFailedWorker('worker returned no evidence');
    }

    try {
        return wsWorkerResult(json_decode($raw, true, flags: JSON_THROW_ON_ERROR));
    } catch (JsonException|WebSocketEvidenceProtocolFailure $error) {
        return wsFailedWorker('worker returned invalid evidence: ' . $error->getMessage());
    }
}

/**
 * @param array{pid:int,path:string} $child
 * @return array{messages:int,successful_messages:int,pings:int,errors:int,timeouts:int,validation_failures:int,latencies:list<float>,error_sample:string}
 */
function wsCollectChild(array $child): array
{
    pcntl_waitpid($child['pid'], $status);
    $raw = is_file($child['path']) ? file_get_contents($child['path']) : false;
    if (is_file($child['path'])) {
        unlink($child['path']);
    }

    return wsChildRecord($status, $raw);
}

/**
 * @param array{messages:int,successful_messages:int,pings:int,errors:int,timeouts:int,validation_failures:int,latencies:list<float>,error_sample:string} $result
 * @throws RuntimeException
 * @throws JsonException
 */
function wsPublishWorker(string $path, array $result): void
{
    if (file_put_contents($path, json_encode($result, JSON_THROW_ON_ERROR)) === false) {
        throw new RuntimeException('Unable to publish WebSocket child evidence.');
    }
}

/**
 * @return list<array{pid:int,path:string}>|null
 * @throws RuntimeException
 * @throws JsonException
 */
function wsStartChildren(int $port, int $concurrency, float $duration): ?array
{
    if (!function_exists('pcntl_fork')) {
        throw new RuntimeException('WebSocket evidence requires pcntl_fork().');
    }

    /** @var list<array{pid: int, path: string}> $children */
    $children = [];
    for ($worker = 0; $worker < $concurrency; ++$worker) {
        $path = tempnam(sys_get_temp_dir(), 'runwire-ws-evidence-');
        if (!is_string($path)) {
            throw new RuntimeException('Unable to create WebSocket evidence result file.');
        }

        $pid = pcntl_fork();
        if ($pid === -1) {
            if (is_file($path)) {
                unlink($path);
            }

            throw new RuntimeException('Unable to fork WebSocket evidence worker.');
        }
        if ($pid === 0) {
            $result = wsWorker($port, $duration, $worker);
            wsPublishWorker($path, $result);

            return null;
        }

        $children[] = ['pid' => $pid, 'path' => $path];
    }

    return $children;
}

/**
 * @return array{messages:int,successful_messages:int,pings:int,errors:int,timeouts:int,validation_failures:int,error_samples:list<string>,duration_seconds:float,messages_per_second:float,latency_ms:array{p50:float,p95:float,p99:float},correctness_passed:bool}|null Null is returned in forked child processes after writing their result.
 * @throws \RuntimeException
   * @throws \JsonException
 */
function wsTrial(int $port, int $concurrency, float $duration): ?array
{
    $children = wsStartChildren($port, $concurrency, $duration);
    if ($children === null) {
        return null;
    }

    $messages = $successful = $pings = $errors = $timeouts = $validationFailures = 0;
    $latencies = [];
    $samples = [];
    $started = microtime(true);

    foreach ($children as $child) {
        $result = wsCollectChild($child);
        $messages += $result['messages'];
        $successful += $result['successful_messages'];
        $pings += $result['pings'];
        $errors += $result['errors'];
        $timeouts += $result['timeouts'];
        $validationFailures += $result['validation_failures'];
        foreach ($result['latencies'] as $latency) {
            $latencies[] = $latency;
        }
        if ($result['error_sample'] !== '' && count($samples) < 10) {
            $samples[] = $result['error_sample'];
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
        'messages_per_second' => round((float) $successful / max($elapsed, 0.000001), 3),
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

    $index = max(0, min(count($values) - 1, (int) ceil($fraction * (float) count($values)) - 1));

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
        : ($values[$middle - 1] + $values[$middle]) / 2.0;
}

/** @param list<float> $values */
function wsCoefficientOfVariation(array $values): float
{
    $count = count($values);
    if ($count < 2) {
        return 0.0;
    }

    $mean = array_sum($values) / (float) $count;
    if ($mean <= 0.0) {
        return 0.0;
    }

    $sum = 0.0;
    foreach ($values as $value) {
        $sum += ($value - $mean) ** 2.0;
    }

    return sqrt($sum / (float) ($count - 1)) / $mean * 100.0;
}

function wsSlowReader(int $port): bool
{
    $owner = new class {
        /** @var resource|closed-resource|null */
        public mixed $socket = null;
    };

    try {
        $socket = wsConnect($port);
        $owner->socket = $socket;
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
        $owner->socket = null;

        return true;
    } catch (Throwable) {
        return false;
    } finally {
        wsReleaseSocket($owner->socket);
    }
}

/**
 * @return numeric-string
 * @throws InvalidArgumentException
 */
function wsOptionValue(mixed $value): string
{
    if ($value === false) {
        return '0';
    }
    if (!is_string($value) || !is_numeric($value) || !is_finite((float) $value)) {
        throw new InvalidArgumentException('WebSocket options require one finite numeric value each.');
    }

    return $value;
}

/**
 * @param array<string,mixed> $options
 * @return array{port:int,concurrency:int,duration:float,trials:int,soak_seconds:float}
 * @throws InvalidArgumentException
 */
function wsOptions(array $options): array
{
    $port = (int) wsOptionValue($options['port'] ?? '0');
    $concurrency = (int) wsOptionValue($options['concurrency'] ?? '8');
    $duration = (float) wsOptionValue($options['duration'] ?? '3.0');
    $trials = (int) wsOptionValue($options['trials'] ?? '5');
    $soakSeconds = (float) wsOptionValue($options['soak-seconds'] ?? '0.0');
    if ($port < 1 || $port > 65_535) {
        throw new InvalidArgumentException('port must be between 1 and 65535');
    }
    if ($concurrency < 1 || $concurrency > 256) {
        throw new InvalidArgumentException('concurrency must be between 1 and 256');
    }
    if ($duration <= 0.0 || $trials < 1 || $soakSeconds < 0.0) {
        throw new InvalidArgumentException('duration/trials must be positive and soak-seconds non-negative');
    }

    return ['port' => $port, 'concurrency' => $concurrency, 'duration' => $duration, 'trials' => $trials, 'soak_seconds' => $soakSeconds];
}

/**
 * @return list<array{messages:int,successful_messages:int,pings:int,errors:int,timeouts:int,validation_failures:int,error_samples:list<string>,duration_seconds:float,messages_per_second:float,latency_ms:array{p50:float,p95:float,p99:float},correctness_passed:bool}>|null
 * @throws RuntimeException
 * @throws \JsonException
 */
function wsTrialSeries(int $port, int $concurrency, float $duration, int $trials): ?array
{
    $records = [];
    for ($trial = 0; $trial < $trials; ++$trial) {
        $record = wsTrial($port, $concurrency, $duration);
        if ($record === null) {
            return null;
        }
        $records[] = $record;
    }

    return $records;
}

/**
 * @param list<array{messages:int,successful_messages:int,pings:int,errors:int,timeouts:int,validation_failures:int,error_samples:list<string>,duration_seconds:float,messages_per_second:float,latency_ms:array{p50:float,p95:float,p99:float},correctness_passed:bool}> $records
 * @return list<string>
 */
function wsErrorSamples(array $records): array
{
    $samples = [];
    foreach ($records as $record) {
        foreach ($record['error_samples'] as $sample) {
            if (count($samples) < 10) {
                $samples[] = $sample;
            }
        }
    }

    return $samples;
}

/**
 * @throws \InvalidArgumentException
 * @throws \RuntimeException
   * @throws \JsonException
 */
function wsMain(): void
{
    $rawOptions = getopt('', ['port:', 'concurrency::', 'duration::', 'trials::', 'soak-seconds::']);
    if ($rawOptions === false) {
        throw new InvalidArgumentException('Unable to read WebSocket benchmark options.');
    }
    $options = wsOptions($rawOptions);
    $port = $options['port'];
    $concurrency = $options['concurrency'];
    $duration = $options['duration'];
    $trials = $options['trials'];
    $soakSeconds = $options['soak_seconds'];

    $slowReaderPassed = wsSlowReader($port);
    $records = wsTrialSeries($port, $concurrency, $duration, $trials);
    if ($records === null) {
        return;
    }

    $soak = null;
    if ($soakSeconds > 0.0) {
        $soak = wsTrial($port, $concurrency, $soakSeconds);
        if ($soak === null) {
            return;
        }
    }

    $rates = array_map(static fn(array $record): float => $record['messages_per_second'], $records);
    $p95 = array_map(static fn(array $record): float => $record['latency_ms']['p95'], $records);
    $p99 = array_map(static fn(array $record): float => $record['latency_ms']['p99'], $records);
    $messages = array_sum(array_map(static fn(array $record): int => $record['messages'], $records));
    $successful = array_sum(array_map(static fn(array $record): int => $record['successful_messages'], $records));
    $pings = array_sum(array_map(static fn(array $record): int => $record['pings'], $records));
    $errors = array_sum(array_map(static fn(array $record): int => $record['errors'], $records));
    $timeouts = array_sum(array_map(static fn(array $record): int => $record['timeouts'], $records));
    $validationFailures = array_sum(array_map(static fn(array $record): int => $record['validation_failures'], $records));
    $samples = wsErrorSamples($records);

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

$scriptFilename = $_SERVER['SCRIPT_FILENAME'] ?? null;
if (is_string($scriptFilename) && realpath($scriptFilename) === __FILE__) {
    wsMain();
}

/**
 * @param resource|closed-resource|null $socket
 * @param-out null $socket
 */
function wsReleaseSocket(mixed &$socket): void
{
    if (is_resource($socket)) {
        fclose($socket);
    }
    $socket = null;
}
