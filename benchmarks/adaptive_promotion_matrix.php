<?php

declare(strict_types=1);

require_once __DIR__ . '/adaptive_matrix_compare.php';

const ADAPTIVE_PROMOTION_MODES = ['fixed', 'latency', 'throughput', 'auto'];
const ADAPTIVE_PROMOTION_PROTOCOLS = ['http1', 'h2', 'h3'];
const ADAPTIVE_PROMOTION_PHASES = ['low_before', 'medium', 'transition_up', 'high', 'transition_down', 'low_after'];

/** @return list<array<string, mixed>> */
function adaptivePromotionCases(string $protocol): array
{
    if (!in_array($protocol, ADAPTIVE_PROMOTION_PROTOCOLS, true)) {
        throw new InvalidArgumentException('Unknown adaptive promotion protocol: ' . $protocol);
    }

    $contents = file_get_contents(__DIR__ . '/adaptive_promotion_cases.json');
    if (!is_string($contents)) {
        throw new RuntimeException('Unable to read adaptive promotion case manifest.');
    }
    $cases = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($cases)) {
        throw new RuntimeException('Adaptive promotion case manifest must decode to an array.');
    }

    return array_values(array_filter(
        $cases,
        static fn(mixed $case): bool => is_array($case) && ($case['protocol'] ?? null) === $protocol,
    ));
}

/** @return array<string, mixed> */
function adaptivePromotionLoadJson(string $path): array
{
    $contents = file_get_contents($path);
    if (!is_string($contents)) {
        throw new RuntimeException('Unable to read adaptive promotion JSON: ' . $path);
    }
    $value = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($value)) {
        throw new RuntimeException('Adaptive promotion JSON must decode to an object.');
    }

    return $value;
}

/** @param array<string, mixed> $value */
function adaptivePromotionWriteJson(string $path, array $value): void
{
    $directory = dirname($path);
    if (!is_dir($directory) && !mkdir($directory, 0o777, true) && !is_dir($directory)) {
        throw new RuntimeException('Unable to create adaptive promotion directory: ' . $directory);
    }
    $temporary = $path . '.tmp';
    $json = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    if (file_put_contents($temporary, $json . PHP_EOL) === false || !rename($temporary, $path)) {
        @unlink($temporary);
        throw new RuntimeException('Unable to publish adaptive promotion JSON: ' . $path);
    }
}

function adaptivePromotionCommandOutput(array $command): string
{
    $process = proc_open(
        $command,
        [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        dirname(__DIR__),
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start command: ' . implode(' ', $command));
    }

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);
    if ($status !== 0 || !is_string($stdout)) {
        throw new RuntimeException(trim((string) $stderr) ?: 'Command failed: ' . implode(' ', $command));
    }

    return trim($stdout);
}

function adaptivePromotionCodeIdentity(): string
{
    $root = dirname(__DIR__);
    $hash = hash_init('sha256');
    foreach (['src', 'benchmarks'] as $directory) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root . '/' . $directory, FilesystemIterator::SKIP_DOTS),
        );
        $paths = [];
        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo || !$file->isFile()) {
                continue;
            }
            $extension = strtolower($file->getExtension());
            if (!in_array($extension, ['php', 'py', 'sh', 'json'], true)) {
                continue;
            }
            $paths[] = $file->getPathname();
        }
        sort($paths, SORT_STRING);
        foreach ($paths as $path) {
            $relative = substr($path, strlen($root) + 1);
            hash_update($hash, $relative . "\0");
            hash_update_file($hash, $path);
        }
    }

    return adaptivePromotionCommandOutput(['git', 'rev-parse', 'HEAD']) . ':' . hash_final($hash);
}

function adaptivePromotionPython(string $protocol): string
{
    return match ($protocol) {
        'http1' => getenv('RUNWIRE_H1_PYTHON') ?: '/usr/bin/python3',
        'h2' => getenv('RUNWIRE_H2_PYTHON') ?: '/tmp/runwire-h2/bin/python',
        'h3' => getenv('RUNWIRE_AIOQUIC_PYTHON') ?: '/tmp/runwire-aioquic/bin/python',
        default => throw new InvalidArgumentException('Unknown adaptive promotion protocol.'),
    };
}

/** @return array<string, mixed> */
function adaptivePromotionEnvironment(string $protocol): array
{
    $required = ['event', 'pcntl', 'posix', 'openssl'];
    if ($protocol === 'h3') {
        $required[] = 'quic';
    }
    foreach ($required as $extension) {
        if (!extension_loaded($extension)) {
            throw new RuntimeException('Prepared environment is missing extension: ' . $extension);
        }
    }
    if (extension_loaded('xdebug')) {
        throw new RuntimeException('Adaptive promotion environment must not load Xdebug.');
    }
    if (!function_exists('opcache_get_status') || opcache_get_status(false) === false) {
        throw new RuntimeException('Adaptive promotion environment requires CLI OPcache.');
    }

    $extensions = [];
    foreach (get_loaded_extensions() as $extension) {
        $version = phpversion($extension);
        $extensions[$extension] = is_string($version) ? $version : 'builtin';
    }
    ksort($extensions, SORT_STRING);

    $python = adaptivePromotionPython($protocol);
    if (!is_executable($python)) {
        throw new RuntimeException('Adaptive promotion Python client is unavailable: ' . $python);
    }
    $client = [
        'runtime' => 'python',
        'python' => adaptivePromotionCommandOutput([$python, '--version']),
    ];
    $package = match ($protocol) {
        'h2' => ['h2', '4.3.0'],
        'h3' => ['aioquic', '1.3.0'],
        default => null,
    };
    if ($package !== null) {
        [$name, $expected] = $package;
        $version = adaptivePromotionCommandOutput([
            $python,
            '-c',
            sprintf('import importlib.metadata; print(importlib.metadata.version(%s))', var_export($name, true)),
        ]);
        if ($version !== $expected) {
            throw new RuntimeException(sprintf('Expected %s==%s, found %s.', $name, $expected, $version));
        }
        $client[$name] = $version;
    }

    $cpu = [];
    $lines = is_readable('/proc/cpuinfo') ? file('/proc/cpuinfo', FILE_IGNORE_NEW_LINES) : false;
    if (is_array($lines)) {
        foreach ($lines as $line) {
            if (preg_match('/^(vendor_id|model name|cpu family|cpu cores|microcode)\s*:/', $line) === 1) {
                $cpu[$line] = true;
            }
        }
    }

    $environment = [
        'host' => gethostname() ?: 'unknown',
        'system' => php_uname(),
        'cpu' => array_keys($cpu),
        'runtime' => [
            'php' => PHP_VERSION,
            'extensions' => $extensions,
            'opcache' => true,
            'ini' => ini_get_all(null, false),
        ],
        'client' => $client,
        'build' => adaptivePromotionCodeIdentity(),
    ];
    $environment['environment_id'] = hash(
        'sha256',
        json_encode($environment, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
    );

    return $environment;
}

/**
 * @param array<string, mixed> $record
 * @param array<string, mixed> $case
 * @param array<string, mixed> $identity
 */
function adaptivePromotionValidateRecord(
    array $record,
    array $case,
    string $mode,
    int $trial,
    array $identity,
): void {
    $protocol = match ($case['protocol']) {
        'http1' => 'http/1.1',
        'h2' => 'h2',
        'h3' => 'h3',
        default => throw new InvalidArgumentException('Unknown promotion protocol.'),
    };
    $expected = [
        'case' => $case,
        'mode' => $mode,
        'trial' => $trial,
        'workload' => $case['id'],
        'protocol' => $protocol,
        'payload_bytes' => $case['payload_bytes'],
        'runtime_build' => $identity['build'],
        'environment' => $identity['environment_id'],
    ];
    foreach ($expected as $key => $value) {
        if (($record[$key] ?? null) !== $value) {
            throw new RuntimeException('Trial identity, workload, mode or environment mismatch: ' . $key);
        }
    }
    if (($record['run_id'] ?? '') === ''
        || ($record['correctness_passed'] ?? false) !== true
        || array_keys($record['phases'] ?? []) !== ADAPTIVE_PROMOTION_PHASES) {
        throw new RuntimeException('Trial is missing identity, correct responses or mandatory phases.');
    }

    foreach ($record['phases'] as $name => $phase) {
        if (!is_array($phase)) {
            throw new RuntimeException('Adaptive promotion phase must be an object.');
        }
        $target = $name === 'medium'
            ? (int) $case['medium']
            : (in_array($name, ['high', 'transition_up'], true) ? (int) $case['high'] : (int) $case['low']);
        if (($phase['concurrency'] ?? null) !== $target || ($phase['correctness_passed'] ?? false) !== true) {
            throw new RuntimeException('Phase did not exercise its declared concurrency correctly.');
        }
        $observed = is_array($phase['observed'] ?? null) ? $phase['observed'] : [];
        $required = match ($case['scenario'] ?? '') {
            'pressure' => 'delayed_reads',
            'flow' => 'constrained_flow_bytes',
            'qpack' => 'qpack_delayed_headers',
            default => null,
        };
        if ($required !== null && (int) ($observed[$required] ?? 0) <= 0) {
            throw new RuntimeException('Case did not exercise ' . $required . '.');
        }
        $upload = (int) ($case['upload_bytes'] ?? 0);
        if ($upload > 0 && (int) ($observed['upload_bytes'] ?? -1) !== (int) ($phase['requests'] ?? 0) * $upload) {
            throw new RuntimeException('Upload workload was not completed and validated.');
        }
        if (($case['scenario'] ?? '') === 'churn'
            && (int) ($observed['connections'] ?? 0) < (int) ceil(((int) ($phase['requests'] ?? 0)) / 8)) {
            throw new RuntimeException('Churn workload did not rotate connections.');
        }
        $clientCpu = $phase['client_cpu_percent'] ?? null;
        if ((!is_int($clientCpu) && !is_float($clientCpu)) || !is_finite((float) $clientCpu) || $clientCpu < 0) {
            throw new RuntimeException('Missing valid generator CPU measurement.');
        }
    }
}

/**
 * @param array<string, array<string, mixed>> $caseResults
 * @return array<string, mixed>
 */
function adaptivePromotionEvaluateProtocol(array $caseResults, string $protocol): array
{
    $required = array_column(adaptivePromotionCases($protocol), 'id');
    $failures = [];
    $gains = [];
    foreach ($required as $name) {
        $result = $caseResults[$name] ?? null;
        if (!is_array($result) || ($result['valid'] ?? false) !== true) {
            $failures[] = $name . ': missing or invalid trial evidence';
            continue;
        }
        $comparisons = is_array($result['comparisons'] ?? null) ? $result['comparisons'] : [];
        foreach (array_slice(ADAPTIVE_PROMOTION_MODES, 0, 3) as $baseline) {
            $comparison = $comparisons[$baseline] ?? null;
            if (!is_array($comparison)
                || ($comparison['passed'] ?? false) !== true
                || ($comparison['measurement_qualified'] ?? false) !== true) {
                $failures[] = $name . ': ' . $baseline . ' regression or measurement gate failed';
            }
        }
        $fixed = $comparisons['fixed']['phases'] ?? [];
        foreach (['transition_up', 'transition_down'] as $phase) {
            $delta = is_array($fixed[$phase] ?? null) ? $fixed[$phase] : [];
            if ((float) ($delta['throughput_ratio'] ?? 0) < 0.99
                || (float) ($delta['p95_absolute_delta_ms'] ?? INF) > 0.1
                || (float) ($delta['p99_absolute_delta_ms'] ?? INF) > 0.1) {
                $failures[] = $name . '/' . $phase . ': transition overhead exceeds 1% throughput or 0.1 ms latency';
            }
        }
        foreach (['low_before', 'medium', 'high', 'low_after'] as $phase) {
            $delta = is_array($fixed[$phase] ?? null) ? $fixed[$phase] : [];
            $ratio = (float) ($delta['throughput_ratio'] ?? 0);
            $noise = (float) ($delta['combined_rps_cv_percent'] ?? INF) * 2;
            if ($ratio >= 1.05 && ($ratio - 1) * 100 > $noise) {
                $gains[] = $name . '/' . $phase;
            }
        }
    }
    if ($gains === []) {
        $failures[] = 'No steady workload demonstrated the preregistered 5% material throughput gain';
    }

    return [
        'protocol' => $protocol,
        'required_cases' => count($required),
        'material_gain_cases' => $gains,
        'failures' => $failures,
        'performance_gate_passed' => $failures === [],
        'decision' => $failures === [] ? 'performance-qualified' : 'not-certified',
        'promotion_certified' => false,
    ];
}

/** @param array<string, mixed> $identity @return array<string, mixed> */
function adaptivePromotionEvaluate(string $output, string $protocol, array $identity): array
{
    $results = [];
    $runIds = [];
    foreach (adaptivePromotionCases($protocol) as $case) {
        $directory = $output . '/' . $case['id'];
        $result = ['valid' => false, 'comparisons' => []];
        try {
            $summaries = [];
            foreach (ADAPTIVE_PROMOTION_MODES as $mode) {
                $records = [];
                for ($trial = 1; $trial <= 5; ++$trial) {
                    $path = sprintf('%s/%s-%d.json', $directory, $mode, $trial);
                    if (is_file(sprintf('%s/%s-%d-failure.json', $directory, $mode, $trial))) {
                        throw new RuntimeException('Recorded failed trial cannot certify a matrix cell.');
                    }
                    $record = adaptivePromotionLoadJson($path);
                    adaptivePromotionValidateRecord($record, $case, $mode, $trial, $identity);
                    $runId = (string) $record['run_id'];
                    if (isset($runIds[$runId])) {
                        throw new RuntimeException('Duplicate trial run ID.');
                    }
                    $runIds[$runId] = true;
                    $records[] = $record;
                }
                $summaries[$mode] = adaptiveMatrixSummarize($records);
                adaptivePromotionWriteJson($directory . '/' . $mode . '-summary.json', $summaries[$mode]);
            }
            foreach (array_slice(ADAPTIVE_PROMOTION_MODES, 0, 3) as $mode) {
                $comparison = adaptiveMatrixCompare($summaries['auto'], $summaries[$mode], false, $mode);
                adaptivePromotionWriteJson($directory . '/auto-vs-' . $mode . '.json', $comparison);
                $result['comparisons'][$mode] = $comparison;
            }
            $result['valid'] = true;
        } catch (Throwable $error) {
            $result['error'] = $error->getMessage();
        }
        $results[(string) $case['id']] = $result;
    }

    $report = adaptivePromotionEvaluateProtocol($results, $protocol) + [
        'build' => $identity['build'],
        'environment' => $identity['environment_id'],
        'cases' => $results,
    ];
    adaptivePromotionWriteJson($output . '/' . $protocol . '-promotion.json', $report);

    return $report;
}

function adaptivePromotionPickPort(bool $udp): int
{
    $server = stream_socket_server(
        ($udp ? 'udp' : 'tcp') . '://127.0.0.1:0',
        $errno,
        $error,
        $udp ? STREAM_SERVER_BIND : STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
    );
    if (!is_resource($server)) {
        throw new RuntimeException($error ?: 'Unable to allocate ephemeral benchmark port.');
    }
    $name = stream_socket_get_name($server, false);
    fclose($server);
    if (!is_string($name) || !str_contains($name, ':')) {
        throw new RuntimeException('Unable to resolve ephemeral benchmark port.');
    }

    return (int) substr(strrchr($name, ':'), 1);
}

/**
 * @param list<string> $command
 * @param array<string, string> $environment
 * @return array{process:resource,pid:int}
 */
function adaptivePromotionStart(array $command, string $stdout, string $stderr, array $environment = []): array
{
    $process = proc_open(
        array_merge(['setsid'], $command),
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', $stdout, 'a'], 2 => ['file', $stderr, 'a']],
        $pipes,
        dirname(__DIR__),
        array_replace(getenv(), $environment),
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start adaptive promotion process.');
    }
    $status = proc_get_status($process);
    if (!is_array($status) || (int) ($status['pid'] ?? 0) < 2) {
        proc_terminate($process);
        proc_close($process);
        throw new RuntimeException('Adaptive promotion process did not expose a PID.');
    }

    return ['process' => $process, 'pid' => (int) $status['pid']];
}

/** @param resource $process */
function adaptivePromotionStop($process, int $pid): void
{
    $status = proc_get_status($process);
    if (!is_array($status)) {
        proc_close($process);

        return;
    }
    if (($status['running'] ?? false) === true) {
        @posix_kill(-$pid, SIGTERM);
        $deadline = microtime(true) + 10;
        do {
            usleep(50_000);
            $status = proc_get_status($process);
        } while (($status['running'] ?? false) === true && microtime(true) < $deadline);
        if (($status['running'] ?? false) === true) {
            @posix_kill(-$pid, SIGKILL);
        }
    }
    proc_close($process);
}

/** @param resource $process */
function adaptivePromotionWait($process, float $timeoutSeconds, string $errorLog): int
{
    $deadline = microtime(true) + $timeoutSeconds;
    do {
        $status = proc_get_status($process);
        if (!is_array($status)) {
            throw new RuntimeException('Unable to inspect adaptive promotion process.');
        }
        if (($status['running'] ?? false) !== true) {
            $code = (int) ($status['exitcode'] ?? -1);
            proc_close($process);

            return $code;
        }
        usleep(50_000);
    } while (microtime(true) < $deadline);

    $pid = (int) ($status['pid'] ?? 0);
    if ($pid > 1) {
        @posix_kill(-$pid, SIGKILL);
    }
    proc_close($process);
    $details = is_file($errorLog) ? trim((string) file_get_contents($errorLog)) : '';

    throw new RuntimeException('Adaptive promotion process timed out. ' . $details);
}

/** @param array<string, mixed> $case @param array<string, mixed> $identity */
function adaptivePromotionRunTrial(
    string $output,
    array $case,
    string $mode,
    int $trial,
    float $seconds,
    float $warmup,
    array $identity,
    string $temporary,
): void {
    $protocol = (string) $case['protocol'];
    $directory = $output . '/' . $case['id'];
    if (!is_dir($directory) && !mkdir($directory, 0o777, true) && !is_dir($directory)) {
        throw new RuntimeException('Unable to create adaptive trial directory.');
    }
    $port = adaptivePromotionPickPort($protocol === 'h3');
    $ready = $temporary . '/ready';
    @unlink($ready);
    $certificate = $temporary . '/cert.pem';
    $privateKey = $temporary . '/key.pem';
    $serverLog = sprintf('%s/%s-%d-server.log', $directory, $mode, $trial);
    $clientLog = sprintf('%s/%s-%d-client.log', $directory, $mode, $trial);
    $resultPath = sprintf('%s/%s-%d.json', $directory, $mode, $trial);

    $server = [PHP_BINARY, '-d', 'opcache.enable_cli=1'];
    if ($protocol === 'h3') {
        array_push(
            $server,
            'benchmarks/adaptive_http3_server.php',
            (string) $port,
            $certificate,
            $privateKey,
            $ready,
            $mode,
            (string) $case['payload_bytes'],
        );
    } else {
        array_push(
            $server,
            'benchmarks/adaptive_http_server.php',
            $protocol,
            (string) $port,
            $mode,
            (string) $case['payload_bytes'],
        );
        if ($protocol === 'h2' || ($case['tls'] ?? false) === true) {
            array_push($server, $certificate, $privateKey);
        }
    }

    $environment = [
        'RUNWIRE_ADAPTIVE_CASE' => json_encode($case, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        'RUNWIRE_ADAPTIVE_TRIAL' => (string) $trial,
        'RUNWIRE_ADAPTIVE_BUILD' => (string) $identity['build'],
        'RUNWIRE_ADAPTIVE_ENVIRONMENT' => (string) $identity['environment_id'],
        'RUNWIRE_ADAPTIVE_WARMUP_SECONDS' => (string) $warmup,
    ];
    $started = adaptivePromotionStart($server, $serverLog, $serverLog, $environment);
    try {
        $deadline = microtime(true) + 10;
        while (true) {
            $status = proc_get_status($started['process']);
            if (($status['running'] ?? false) !== true) {
                throw new RuntimeException('Benchmark server exited before readiness.');
            }
            if ($protocol === 'h3') {
                if (is_file($ready)) {
                    break;
                }
            } else {
                $socket = @stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $error, 0.05);
                if (is_resource($socket)) {
                    fclose($socket);
                    break;
                }
            }
            if (microtime(true) >= $deadline) {
                throw new RuntimeException('Benchmark server failed readiness.');
            }
            usleep(50_000);
        }

        $client = match ($protocol) {
            'http1' => [adaptivePromotionPython('http1'), 'benchmarks/http1_adaptive_matrix.py', (string) $port, (string) $started['pid'], $mode, (string) $seconds],
            'h2' => [adaptivePromotionPython('h2'), 'benchmarks/h2_adaptive_matrix.py', (string) $port, (string) $started['pid'], $mode, (string) $seconds, (string) $case['payload_bytes']],
            'h3' => [adaptivePromotionPython('h3'), 'benchmarks/http3_adaptive_matrix.py', (string) $port, (string) $started['pid'], $mode, (string) $seconds, (string) $case['payload_bytes']],
        };
        $clientRun = adaptivePromotionStart($client, $resultPath . '.raw', $clientLog, $environment);
        $timeout = $warmup + (4 * $seconds) + 90;
        $code = adaptivePromotionWait($clientRun['process'], $timeout, $clientLog);
        if ($code !== 0) {
            throw new RuntimeException('Adaptive promotion client failed with exit code ' . $code . '.');
        }
        $record = adaptivePromotionLoadJson($resultPath . '.raw');
        @unlink($resultPath . '.raw');
        $record['run_id'] = bin2hex(random_bytes(16));
        adaptivePromotionValidateRecord($record, $case, $mode, $trial, $identity);
    } finally {
        adaptivePromotionStop($started['process'], $started['pid']);
    }

    if (adaptivePromotionCodeIdentity() !== $identity['build']) {
        throw new RuntimeException('Source changed during the trial; use one immutable candidate.');
    }
    adaptivePromotionWriteJson($resultPath, $record);
}

/** @return array{action:string,protocol:string,output:string,cases:list<string>,seconds:float,warmup:float,diagnostic:bool,resume:bool} */
function adaptivePromotionArguments(array $argv): array
{
    $action = $argv[1] ?? '';
    if (!in_array($action, ['list', 'run', 'evaluate'], true)) {
        throw new InvalidArgumentException('Usage: php adaptive_promotion_matrix.php <list|run|evaluate> --protocol <http1|h2|h3> [options]');
    }

    $options = [
        'action' => $action,
        'protocol' => '',
        'output' => 'benchmark-results/adaptive-promotion',
        'cases' => [],
        'seconds' => 180.0,
        'warmup' => 30.0,
        'diagnostic' => false,
        'resume' => false,
    ];
    for ($index = 2, $count = count($argv); $index < $count; ++$index) {
        $argument = $argv[$index];
        if ($argument === '--diagnostic') {
            $options['diagnostic'] = true;
        } elseif ($argument === '--resume') {
            $options['resume'] = true;
        } elseif (in_array($argument, ['--protocol', '--output', '--case', '--seconds', '--warmup'], true)) {
            $value = $argv[++$index] ?? throw new InvalidArgumentException('Missing value for ' . $argument);
            match ($argument) {
                '--protocol' => $options['protocol'] = $value,
                '--output' => $options['output'] = $value,
                '--case' => $options['cases'][] = $value,
                '--seconds' => $options['seconds'] = (float) $value,
                '--warmup' => $options['warmup'] = (float) $value,
            };
        } else {
            throw new InvalidArgumentException('Unknown adaptive promotion option: ' . $argument);
        }
    }
    if (!in_array($options['protocol'], ADAPTIVE_PROMOTION_PROTOCOLS, true)) {
        throw new InvalidArgumentException('--protocol must be http1, h2 or h3.');
    }

    return $options;
}

function adaptivePromotionMain(array $argv): int
{
    $options = adaptivePromotionArguments($argv);
    $selected = array_values(array_filter(
        adaptivePromotionCases($options['protocol']),
        static fn(array $case): bool => $options['cases'] === [] || in_array($case['id'], $options['cases'], true),
    ));
    if ($options['cases'] !== [] && array_diff($options['cases'], array_column($selected, 'id')) !== []) {
        throw new InvalidArgumentException('Unknown adaptive promotion case ID.');
    }
    if ($options['action'] === 'list') {
        fwrite(STDOUT, json_encode($selected, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);

        return 0;
    }

    $output = rtrim((string) $options['output'], '/');
    $environmentPath = $output . '/' . $options['protocol'] . '-environment.json';
    if ($options['action'] === 'evaluate') {
        $report = adaptivePromotionEvaluate(
            $output,
            $options['protocol'],
            adaptivePromotionLoadJson($environmentPath),
        );
        $summary = array_diff_key($report, ['cases' => true]);
        fwrite(STDOUT, json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);

        return ($report['performance_gate_passed'] ?? false) === true ? 0 : 1;
    }

    if (!is_finite($options['seconds'])
        || !is_finite($options['warmup'])
        || $options['seconds'] < 1
        || $options['warmup'] < 1) {
        throw new InvalidArgumentException('Durations must be finite and at least one second.');
    }
    if (!$options['diagnostic'] && ($options['seconds'] < 180 || $options['warmup'] < 30)) {
        throw new InvalidArgumentException('Sustained runs require --seconds >= 180 and --warmup >= 30.');
    }

    $identity = adaptivePromotionEnvironment($options['protocol']);
    if (!is_dir($output) && !mkdir($output, 0o777, true) && !is_dir($output)) {
        throw new RuntimeException('Unable to create adaptive promotion output directory.');
    }
    if (is_file($environmentPath)) {
        if (!$options['resume'] || adaptivePromotionLoadJson($environmentPath) !== $identity) {
            throw new RuntimeException('Use a fresh output directory or --resume with exactly matching build/environment.');
        }
    } else {
        adaptivePromotionWriteJson($environmentPath, $identity);
    }

    $temporary = sys_get_temp_dir() . '/runwire-j10-' . bin2hex(random_bytes(8));
    if (!mkdir($temporary, 0o700, true) && !is_dir($temporary)) {
        throw new RuntimeException('Unable to create adaptive promotion temporary directory.');
    }
    try {
        $openssl = [
            'openssl', 'req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-days', '7',
            '-subj', '/CN=localhost', '-keyout', $temporary . '/key.pem', '-out', $temporary . '/cert.pem',
        ];
        adaptivePromotionCommandOutput($openssl);

        foreach ($selected as $case) {
            for ($trial = 1; $trial <= 5; ++$trial) {
                $offset = ($trial - 1) % 4;
                $order = $trial < 5
                    ? array_merge(
                        array_slice(ADAPTIVE_PROMOTION_MODES, $offset),
                        array_slice(ADAPTIVE_PROMOTION_MODES, 0, $offset),
                    )
                    : array_reverse(ADAPTIVE_PROMOTION_MODES);
                foreach ($order as $mode) {
                    $directory = $output . '/' . $case['id'];
                    $recordPath = sprintf('%s/%s-%d.json', $directory, $mode, $trial);
                    $failurePath = sprintf('%s/%s-%d-failure.json', $directory, $mode, $trial);
                    if (is_file($failurePath)) {
                        throw new RuntimeException('A failed trial cannot be silently retried; use a fresh output directory.');
                    }
                    if (is_file($recordPath) && $options['resume']) {
                        adaptivePromotionValidateRecord(
                            adaptivePromotionLoadJson($recordPath),
                            $case,
                            $mode,
                            $trial,
                            $identity,
                        );
                        continue;
                    }
                    fwrite(STDOUT, sprintf("%s %s trial %d/5\n", $case['id'], $mode, $trial));
                    try {
                        adaptivePromotionRunTrial(
                            $output,
                            $case,
                            $mode,
                            $trial,
                            $options['seconds'],
                            $options['warmup'],
                            $identity,
                            $temporary,
                        );
                    } catch (Throwable $error) {
                        adaptivePromotionWriteJson($failurePath, [
                            'error' => $error->getMessage(),
                            'build' => $identity['build'],
                            'mode' => $mode,
                            'trial' => $trial,
                        ]);
                        throw $error;
                    }
                }
            }
        }
    } finally {
        foreach (glob($temporary . '/*') ?: [] as $path) {
            @unlink($path);
        }
        @rmdir($temporary);
    }

    $report = adaptivePromotionEvaluate($output, $options['protocol'], $identity);
    fwrite(
        STDOUT,
        sprintf(
            "%s: %s (%d unmet requirements)\n",
            $options['protocol'],
            $report['decision'],
            count($report['failures']),
        ),
    );

    return ($report['performance_gate_passed'] ?? false) === true ? 0 : 1;
}

if (realpath($argv[0] ?? '') === __FILE__) {
    exit(adaptivePromotionMain($argv));
}
