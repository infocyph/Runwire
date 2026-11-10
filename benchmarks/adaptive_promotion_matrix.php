<?php

declare(strict_types=1);

require_once __DIR__ . '/adaptive_matrix_compare.php';

const ADAPTIVE_PROMOTION_MODES = ['fixed', 'latency', 'throughput', 'auto'];
const ADAPTIVE_PROMOTION_PROTOCOLS = ['http1', 'h2', 'h3'];
const ADAPTIVE_PROMOTION_PHASES = ['low_before', 'medium', 'transition_up', 'high', 'transition_down', 'low_after'];

/**
 * The only protocol values accepted by the developer CLI.
 *
 * @throws \InvalidArgumentException
 * @psalm-taint-escape file
 * @psalm-taint-escape ssrf
 * @psalm-taint-escape url_component
 */
function adaptivePromotionCheckedProtocol(string $protocol): string
{
    if (!in_array($protocol, ADAPTIVE_PROMOTION_PROTOCOLS, true)) {
        throw new InvalidArgumentException('--protocol must be http1, h2 or h3.');
    }

    return $protocol;
}

/**
 * @param array<array-key, mixed> $case
 * @throws RuntimeException
 */
function adaptivePromotionCaseString(array $case, string $field): string
{
    $value = $case[$field] ?? null;
    if (!is_string($value) || $value === '') {
        throw new RuntimeException('Adaptive case requires a nonempty string: ' . $field);
    }

    return $value;
}

/**
 * @param array<array-key, mixed> $case
 * @throws RuntimeException
 */
function adaptivePromotionCaseInt(array $case, string $field): int
{
    $value = $case[$field] ?? null;
    if (!is_int($value) || $value < 0) {
        throw new RuntimeException('Adaptive case requires a nonnegative integer: ' . $field);
    }

    return $value;
}

/**
 * @phpstan-assert array{id:string,protocol:string,payload_bytes:int,scenario:string,low:int,medium:int,high:int,tls?:bool,keepalive?:int,upload_bytes?:int,...<array-key,mixed>} $value
 * @psalm-assert array{id:string,protocol:string,payload_bytes:int,scenario:string,low:int,medium:int,high:int,tls?:bool,keepalive?:int,upload_bytes?:int,...<array-key,mixed>} $value
 * @throws RuntimeException
 */
function adaptivePromotionValidateCase(mixed $value): void
{
    if (!is_array($value)) {
        throw new RuntimeException('Adaptive case must be an object.');
    }
    $case = [
        'id' => adaptivePromotionCaseString($value, 'id'),
        'protocol' => adaptivePromotionCaseString($value, 'protocol'),
        'payload_bytes' => adaptivePromotionCaseInt($value, 'payload_bytes'),
        'scenario' => adaptivePromotionCaseString($value, 'scenario'),
        'low' => adaptivePromotionCaseInt($value, 'low'),
        'medium' => adaptivePromotionCaseInt($value, 'medium'),
        'high' => adaptivePromotionCaseInt($value, 'high'),
    ];
    if (preg_match('/^[a-zA-Z0-9_-]+$/D', $case['id']) !== 1
        || !in_array($case['protocol'], ADAPTIVE_PROMOTION_PROTOCOLS, true)) {
        throw new RuntimeException('Adaptive case has an invalid identity or protocol.');
    }
    foreach (['keepalive', 'upload_bytes'] as $field) {
        if (array_key_exists($field, $value)) {
            $case[$field] = adaptivePromotionCaseInt($value, $field);
        }
    }
    if (array_key_exists('tls', $value)) {
        if (!is_bool($value['tls'])) {
            throw new RuntimeException('Adaptive case TLS flag must be boolean.');
        }
        $case['tls'] = $value['tls'];
    }

}

/**
 * @return array{id:string,protocol:string,payload_bytes:int,scenario:string,low:int,medium:int,high:int,tls?:bool,keepalive?:int,upload_bytes?:int,...<array-key,mixed>}
 * @throws RuntimeException
 */
function adaptivePromotionCase(mixed $value): array
{
    adaptivePromotionValidateCase($value);

    return $value;
}

/**
 * @return list<array{id:string,protocol:string,payload_bytes:int,scenario:string,low:int,medium:int,high:int,tls?:bool,keepalive?:int,upload_bytes?:int,...<array-key,mixed>}>
 * @throws \InvalidArgumentException
 * @throws \RuntimeException
 */
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

    $validated = array_map(adaptivePromotionCase(...), $cases);

    return array_values(array_filter(
        $validated,
        static fn(array $case): bool => $case['protocol'] === $protocol,
    ));
}

/**
 * @return array<string, mixed>
 * @throws \RuntimeException
 */
function adaptivePromotionLoadJson(string $path): array
{
    return adaptiveMatrixLoad($path);
}

/**
 * Reserve a non-predictable same-directory file for atomic JSON publication.
 *
 * @throws \RuntimeException
 */
function adaptivePromotionTemporaryFile(string $directory): string
{
    $canonicalDirectory = realpath($directory);
    if ($canonicalDirectory === false || !is_writable($canonicalDirectory)) {
        throw new RuntimeException('Adaptive promotion directory is not writable.');
    }
    $temporary = tempnam($canonicalDirectory, 'runwire-promotion-');
    if (!is_string($temporary) || dirname($temporary) !== $canonicalDirectory) {
        if (is_string($temporary) && is_file($temporary)) {
            unlink($temporary);
        }

        throw new RuntimeException('Unable to reserve a local adaptive promotion temporary file.');
    }

    return $temporary;
}

/**
 * @param array<string, mixed> $value
 * @throws \RuntimeException
 */
function adaptivePromotionWriteJson(string $path, array $value): void
{
    $directory = dirname($path);
    if (!is_dir($directory) && !mkdir($directory, 0o777, true) && !is_dir($directory)) {
        throw new RuntimeException('Unable to create adaptive promotion directory: ' . $directory);
    }

    $path = runwireBenchmarkOutputPath($path);
    $temporary = adaptivePromotionTemporaryFile($directory);

    try {
        $json = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (file_put_contents($temporary, $json . PHP_EOL) === false || !rename($temporary, $path)) {
            throw new RuntimeException('Unable to publish adaptive promotion JSON: ' . $path);
        }
    } finally {
        if (is_file($temporary)) {
            unlink($temporary);
        }
    }
}

/**
 * @param list<string> $command
 * @throws \RuntimeException
 */
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

/**
 * @throws \RuntimeException
 */
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
    $variable = match ($protocol) {
        'http1' => 'RUNWIRE_H1_PYTHON',
        'h2' => 'RUNWIRE_H2_PYTHON',
        'h3' => 'RUNWIRE_AIOQUIC_PYTHON',
        default => throw new InvalidArgumentException('Unknown adaptive promotion protocol.'),
    };
    $fallback = match ($protocol) {
        'http1' => '/usr/bin/python3',
        'h2' => '/tmp/runwire-h2/bin/python',
        default => '/tmp/runwire-aioquic/bin/python',
    };
    $configured = getenv($variable);

    return $configured !== false && $configured !== '' && $configured !== '0' ? $configured : $fallback;
}

/**
 * @throws RuntimeException
 */
function adaptivePromotionAssertEnvironment(string $protocol): void
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
}

/**
 * @return array<string,string>
 * @throws RuntimeException
 */
function adaptivePromotionExtensions(): array
{
    $extensions = [];
    foreach (get_loaded_extensions() as $extension) {
        $version = phpversion($extension);
        $extensions[$extension] = is_string($version) ? $version : 'builtin';
    }
    ksort($extensions, SORT_STRING);

    return $extensions;
}

/**
 * @return array<string,string>
 * @throws RuntimeException
 */
function adaptivePromotionClientIdentity(string $protocol): array
{
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

    return $client;
}

/**
 * @return list<string>
 * @throws RuntimeException
 */
function adaptivePromotionCpuIdentity(): array
{
    $cpu = [];
    $lines = is_readable('/proc/cpuinfo') ? file('/proc/cpuinfo', FILE_IGNORE_NEW_LINES) : false;
    if (is_array($lines)) {
        foreach ($lines as $line) {
            if (preg_match('/^(vendor_id|model name|cpu family|cpu cores|microcode)\s*:/', $line) === 1) {
                $cpu[] = $line;
            }
        }
    }

    return array_values(array_unique($cpu));
}

/**
 * @return array{host:string,system:string,cpu:list<string>,runtime:array<string,mixed>,client:array<string,string>,build:string,environment_id:string}
 * @throws \RuntimeException
 */
function adaptivePromotionEnvironment(string $protocol): array
{
    adaptivePromotionAssertEnvironment($protocol);
    $extensions = adaptivePromotionExtensions();
    $client = adaptivePromotionClientIdentity($protocol);
    $cpu = adaptivePromotionCpuIdentity();

    $hostname = gethostname();
    $environment = [
        'host' => $hostname !== false && $hostname !== '' && $hostname !== '0' ? $hostname : 'unknown',
        'system' => php_uname(),
        'cpu' => $cpu,
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
 * @param array<string,mixed> $record
 * @param array{id:string,protocol:string,payload_bytes:int,scenario:string,low:int,medium:int,high:int,tls?:bool,keepalive?:int,upload_bytes?:int,...<array-key,mixed>} $case
 * @param array{build:string,environment_id:string,...<string,mixed>} $identity
 * @throws RuntimeException
 */
function adaptivePromotionValidateIdentity(array $record, array $case, string $mode, int $trial, array $identity): void
{
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
}

/**
 * @param array<string,mixed> $record
 * @param array{id:string,protocol:string,payload_bytes:int,scenario:string,low:int,medium:int,high:int,tls?:bool,keepalive?:int,upload_bytes?:int,...<array-key,mixed>} $case
 * @param array{build:string,environment_id:string,...<string,mixed>} $identity
 * @return array{run_id:non-empty-string,...<string,mixed>}
 * @throws RuntimeException
 */
function adaptivePromotionValidateRecord(array $record, array $case, string $mode, int $trial, array $identity): array
{
    adaptivePromotionValidateIdentity($record, $case, $mode, $trial, $identity);
    $phases = $record['phases'] ?? null;
    $runId = $record['run_id'] ?? null;
    if (!is_string($runId) || $runId === ''
        || ($record['correctness_passed'] ?? false) !== true
        || !is_array($phases) || array_keys($phases) !== ADAPTIVE_PROMOTION_PHASES) {
        throw new RuntimeException('Trial is missing identity, correct responses or mandatory phases.');
    }

    foreach ($phases as $name => $value) {
        $phase = adaptiveMatrixPhase($value, $name);
        $target = match ($name) {
            'medium' => $case['medium'],
            'high', 'transition_up' => $case['high'],
            default => $case['low'],
        };
        if (($phase['concurrency'] ?? null) !== $target || ($phase['correctness_passed'] ?? false) !== true) {
            throw new RuntimeException('Phase did not exercise its declared concurrency correctly.');
        }
        adaptivePromotionValidateObserved($phase, $case);
        adaptiveMatrixMetric($phase, 'client_cpu_percent');
    }

    $record['run_id'] = $runId;

    return $record;
}

/**
 * @param array<string,mixed> $phase
 * @param array{id:string,protocol:string,payload_bytes:int,scenario:string,low:int,medium:int,high:int,tls?:bool,keepalive?:int,upload_bytes?:int,...<array-key,mixed>} $case
 * @throws RuntimeException
 */
function adaptivePromotionValidateObserved(array $phase, array $case): void
{
    $observed = adaptiveMatrixPhase($phase['observed'] ?? [], 'observed');
    $required = match ($case['scenario']) {
        'pressure' => 'delayed_reads',
        'flow' => 'constrained_flow_bytes',
        'qpack' => 'qpack_delayed_headers',
        default => null,
    };
    if ($required !== null && adaptivePromotionCaseInt($observed, $required) <= 0) {
        throw new RuntimeException('Case did not exercise ' . $required . '.');
    }
    adaptivePromotionValidateTraffic($phase, $observed, $case);
}

/**
 * @param array<string,mixed> $phase
 * @param array<string,mixed> $observed
 * @param array{id:string,protocol:string,payload_bytes:int,scenario:string,low:int,medium:int,high:int,tls?:bool,keepalive?:int,upload_bytes?:int,...<array-key,mixed>} $case
 * @throws RuntimeException
 */
function adaptivePromotionValidateTraffic(array $phase, array $observed, array $case): void
{
    $upload = $case['upload_bytes'] ?? 0;
    if ($upload > 0
        && adaptivePromotionCaseInt($observed, 'upload_bytes') !== adaptivePromotionCaseInt($phase, 'requests') * $upload) {
        throw new RuntimeException('Upload workload was not completed and validated.');
    }
    if ($case['scenario'] === 'churn'
        && adaptivePromotionCaseInt($observed, 'connections') < (int) ceil(adaptivePromotionCaseInt($phase, 'requests') / 8)) {
        throw new RuntimeException('Churn workload did not rotate connections.');
    }
}

/** Treat invalid delta metrics as failed evidence instead of coercing them into passing values. */
function adaptivePromotionDelta(mixed $value, float $fallback): float
{
    if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value)) {
        return $fallback;
    }

    return (float) $value;
}

/**
 * @param array<array-key,mixed> $fixed
 * @return list<string>
 */
function adaptivePromotionTransitionFailures(string $name, array $fixed): array
{
    $failures = [];
    foreach (['transition_up', 'transition_down'] as $phase) {
        $delta = is_array($fixed[$phase] ?? null) ? $fixed[$phase] : [];
        if (adaptivePromotionDelta($delta['throughput_ratio'] ?? null, 0.0) < 0.99
            || adaptivePromotionDelta($delta['p95_absolute_delta_ms'] ?? null, INF) > 0.1
            || adaptivePromotionDelta($delta['p99_absolute_delta_ms'] ?? null, INF) > 0.1) {
            $failures[] = $name . '/' . $phase . ': transition overhead exceeds 1% throughput or 0.1 ms latency';
        }
    }

    return $failures;
}

/**
 * @param array<array-key,mixed> $fixed
 * @return list<string>
 */
function adaptivePromotionMaterialGains(string $name, array $fixed): array
{
    $gains = [];
    foreach (['low_before', 'medium', 'high', 'low_after'] as $phase) {
        $delta = is_array($fixed[$phase] ?? null) ? $fixed[$phase] : [];
        $ratio = adaptivePromotionDelta($delta['throughput_ratio'] ?? null, 0.0);
        $noise = adaptivePromotionDelta($delta['combined_rps_cv_percent'] ?? null, INF) * 2.0;
        if ($ratio >= 1.05 && ($ratio - 1.0) * 100.0 > $noise) {
            $gains[] = $name . '/' . $phase;
        }
    }

    return $gains;
}

/** @return array{failures:list<string>,gains:list<string>} */
function adaptivePromotionEvaluateCase(string $name, mixed $result): array
{
    if (!is_array($result) || ($result['valid'] ?? false) !== true) {
        return ['failures' => [$name . ': missing or invalid trial evidence'], 'gains' => []];
    }
    $failures = [];
    $comparisons = is_array($result['comparisons'] ?? null) ? $result['comparisons'] : [];
    foreach (array_slice(ADAPTIVE_PROMOTION_MODES, 0, 3) as $baseline) {
        $comparison = $comparisons[$baseline] ?? null;
        if (!is_array($comparison)
            || ($comparison['passed'] ?? false) !== true
            || ($comparison['measurement_qualified'] ?? false) !== true) {
            $failures[] = $name . ': ' . $baseline . ' regression or measurement gate failed';
        }
    }
    $fixedComparison = $comparisons['fixed'] ?? null;
    $phases = is_array($fixedComparison) ? ($fixedComparison['phases'] ?? null) : null;
    $fixed = is_array($phases) ? $phases : [];

    return [
        'failures' => array_merge($failures, adaptivePromotionTransitionFailures($name, $fixed)),
        'gains' => adaptivePromotionMaterialGains($name, $fixed),
    ];
}

/**
 * @param array<string,array<string,mixed>> $caseResults
 * @return array{protocol:string,required_cases:int,material_gain_cases:list<string>,failures:list<string>,performance_gate_passed:bool,decision:string,promotion_certified:bool}
 * @throws \InvalidArgumentException
 * @throws \RuntimeException
 */
function adaptivePromotionEvaluateProtocol(array $caseResults, string $protocol): array
{
    $required = array_column(adaptivePromotionCases($protocol), 'id');
    $failures = [];
    $gains = [];
    foreach ($required as $name) {
        $case = adaptivePromotionEvaluateCase($name, $caseResults[$name] ?? null);
        array_push($failures, ...$case['failures']);
        array_push($gains, ...$case['gains']);
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

/**
 * @param array{id:string,protocol:string,payload_bytes:int,scenario:string,low:int,medium:int,high:int,tls?:bool,keepalive?:int,upload_bytes?:int,...<array-key,mixed>} $case
 * @param array{build:string,environment_id:string,...<string,mixed>} $identity
 * @param array<string,true> $runIds
 * @return array<string,mixed>
 * @throws RuntimeException
 * @throws InvalidArgumentException
 */
function adaptivePromotionSummarizeMode(string $directory, array $case, string $mode, array $identity, array &$runIds): array
{
    $records = [];
    for ($trial = 1; $trial <= 5; ++$trial) {
        $path = sprintf('%s/%s-%d.json', $directory, $mode, $trial);
        if (is_file(sprintf('%s/%s-%d-failure.json', $directory, $mode, $trial))) {
            throw new RuntimeException('Recorded failed trial cannot certify a matrix cell.');
        }
        $record = adaptivePromotionLoadJson($path);
        $record = adaptivePromotionValidateRecord($record, $case, $mode, $trial, $identity);
        $runId = $record['run_id'];
        if (isset($runIds[$runId])) {
            throw new RuntimeException('Duplicate trial run ID.');
        }
        $runIds[$runId] = true;
        $records[] = $record;
    }
    $summary = adaptiveMatrixSummarize($records);
    adaptivePromotionWriteJson($directory . '/' . $mode . '-summary.json', $summary);

    return $summary;
}

/**
 * @param array{build:string,environment_id:string,...<string,mixed>} $identity
 * @return array{protocol:string,required_cases:int,material_gain_cases:list<string>,failures:list<string>,performance_gate_passed:bool,decision:string,promotion_certified:bool,build:string,environment:string,cases:array<string,array{valid:bool,comparisons:array<string,array{protocol:mixed,baseline_mode:string,workload:mixed,scope:string,diagnostic:bool,promotion_certified:bool,evidence_failures:list<string>,measurement_qualified:bool,trials:int,budgets:array<string,int|float|string>,phases:array<string,array<string,int|float>>,failures:list<string>,passed:bool}>,error?:string}>}
 * @throws \InvalidArgumentException
 * @throws \RuntimeException
 */
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
                $summaries[$mode] = adaptivePromotionSummarizeMode($directory, $case, $mode, $identity, $runIds);
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
        $results[$case['id']] = $result;
    }

    $report = adaptivePromotionEvaluateProtocol($results, $protocol) + [
        'build' => $identity['build'],
        'environment' => $identity['environment_id'],
        'cases' => $results,
    ];
    adaptivePromotionWriteJson($output . '/' . $protocol . '-promotion.json', $report);

    return $report;
}

function adaptivePromotionCanConnect(int $port): bool
{
    set_error_handler(static fn(): bool => true);

    try {
        $socket = stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $error, 0.05);
    } finally {
        restore_error_handler();
    }
    if (!is_resource($socket)) {
        return false;
    }

    fclose($socket);

    return true;
}

/**
 * @throws \RuntimeException
 */
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

    $separator = strrpos($name, ':');
    if ($separator === false) {
        throw new RuntimeException('Unable to resolve ephemeral benchmark port.');
    }

    return (int) substr($name, $separator + 1);
}

/**
 * @param list<string> $command
 * @param array<string, string> $environment
 * @return array{process:resource,pid:int}
 * @throws \RuntimeException
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
    if ($status['pid'] < 2) {
        proc_terminate($process);
        proc_close($process);

        throw new RuntimeException('Adaptive promotion process did not expose a PID.');
    }

    return ['process' => $process, 'pid' => $status['pid']];
}

/** @param resource $process */
function adaptivePromotionStop($process, int $pid): void
{
    $running = adaptivePromotionRunning($process);
    if ($running) {
        posix_kill(-$pid, SIGTERM);
        $deadline = microtime(true) + 10.0;
        do {
            usleep(50_000);
            $running = adaptivePromotionRunning($process);
        } while ($running && microtime(true) < $deadline);
        if ($running) {
            posix_kill(-$pid, SIGKILL);
        }
    }
    proc_close($process);
}

/**
 * @param resource $process
 * @throws \RuntimeException
 */
function adaptivePromotionWait($process, float $timeoutSeconds, string $errorLog): int
{
    $deadline = microtime(true) + $timeoutSeconds;
    do {
        $status = proc_get_status($process);
        if (!$status['running']) {
            $code = $status['exitcode'];
            proc_close($process);

            return $code;
        }
        usleep(50_000);
    } while (microtime(true) < $deadline);

    $pid = $status['pid'];
    if ($pid > 1) {
        posix_kill(-$pid, SIGKILL);
    }
    proc_close($process);
    $details = is_file($errorLog) ? trim((string) file_get_contents($errorLog)) : '';

    throw new RuntimeException('Adaptive promotion process timed out. ' . $details);
}

/** @param array{id:string,protocol:string,payload_bytes:int,scenario:string,low:int,medium:int,high:int,tls?:bool,keepalive?:int,upload_bytes?:int,...<array-key,mixed>} $case
 * @return list<string>
 */
function adaptivePromotionServerCommand(array $case, string $mode, int $port, string $certificate, string $privateKey, string $ready): array
{
    $protocol = $case['protocol'];
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

    return $server;
}

/**
 * @param resource $process
 * @throws RuntimeException
 */
function adaptivePromotionWaitReady($process, string $protocol, int $port, string $ready): void
{
    $deadline = microtime(true) + 10.0;
    while (true) {
        $status = proc_get_status($process);
        if (!$status['running']) {
            throw new RuntimeException('Benchmark server exited before readiness.');
        }
        $readyNow = $protocol === 'h3' ? is_file($ready) : adaptivePromotionCanConnect($port);
        if ($readyNow) {
            break;
        }
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Benchmark server failed readiness.');
        }
        usleep(50_000);
    }

}

/**
 * @return list<string>
 * @throws InvalidArgumentException
 * @throws RuntimeException
 */
function adaptivePromotionClientCommand(string $protocol, int $port, int $pid, string $mode, float $seconds, int $payloadBytes): array
{
    return match ($protocol) {
        'http1' => [adaptivePromotionPython('http1'), 'benchmarks/http1_adaptive_matrix.py', (string) $port, (string) $pid, $mode, (string) $seconds],
        'h2' => [adaptivePromotionPython('h2'), 'benchmarks/h2_adaptive_matrix.py', (string) $port, (string) $pid, $mode, (string) $seconds, (string) $payloadBytes],
        'h3' => [adaptivePromotionPython('h3'), 'benchmarks/http3_adaptive_matrix.py', (string) $port, (string) $pid, $mode, (string) $seconds, (string) $payloadBytes],
        default => throw new InvalidArgumentException('Unknown adaptive protocol.'),
    };
}

/**
 * @param array{id:string,protocol:string,payload_bytes:int,scenario:string,low:int,medium:int,high:int,tls?:bool,keepalive?:int,upload_bytes?:int,...<array-key,mixed>} $case
 * @param array{build:string,environment_id:string,...<string,mixed>} $identity
 * @throws \RuntimeException
   * @throws \InvalidArgumentException
 */
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
    $protocol = $case['protocol'];
    $directory = $output . '/' . $case['id'];
    if (!is_dir($directory) && !mkdir($directory, 0o777, true) && !is_dir($directory)) {
        throw new RuntimeException('Unable to create adaptive trial directory.');
    }
    $port = adaptivePromotionPickPort($protocol === 'h3');
    $ready = $temporary . '/ready';
    if (is_file($ready)) {
        unlink($ready);
    }
    $certificate = $temporary . '/cert.pem';
    $privateKey = $temporary . '/key.pem';
    $serverLog = sprintf('%s/%s-%d-server.log', $directory, $mode, $trial);
    $clientLog = sprintf('%s/%s-%d-client.log', $directory, $mode, $trial);
    $resultPath = sprintf('%s/%s-%d.json', $directory, $mode, $trial);

    $server = adaptivePromotionServerCommand($case, $mode, $port, $certificate, $privateKey, $ready);

    $environment = [
        'RUNWIRE_ADAPTIVE_CASE' => json_encode($case, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        'RUNWIRE_ADAPTIVE_TRIAL' => (string) $trial,
        'RUNWIRE_ADAPTIVE_BUILD' => $identity['build'],
        'RUNWIRE_ADAPTIVE_ENVIRONMENT' => $identity['environment_id'],
        'RUNWIRE_ADAPTIVE_WARMUP_SECONDS' => (string) $warmup,
    ];
    $started = adaptivePromotionStart($server, $serverLog, $serverLog, $environment);

    try {
        adaptivePromotionWaitReady($started['process'], $protocol, $port, $ready);
        $client = adaptivePromotionClientCommand($protocol, $port, $started['pid'], $mode, $seconds, $case['payload_bytes']);
        $clientRun = adaptivePromotionStart($client, $resultPath . '.raw', $clientLog, $environment);
        $timeout = $warmup + (4.0 * $seconds) + 90.0;
        $code = adaptivePromotionWait($clientRun['process'], $timeout, $clientLog);
        if ($code !== 0) {
            throw new RuntimeException('Adaptive promotion client failed with exit code ' . $code . '.');
        }
        $record = adaptivePromotionLoadJson($resultPath . '.raw');
        if (is_file($resultPath . '.raw')) {
            unlink($resultPath . '.raw');
        }
        $record['run_id'] = bin2hex(random_bytes(16));
        $record = adaptivePromotionValidateRecord($record, $case, $mode, $trial, $identity);
    } finally {
        adaptivePromotionStop($started['process'], $started['pid']);
    }

    if (adaptivePromotionCodeIdentity() !== $identity['build']) {
        throw new RuntimeException('Source changed during the trial; use one immutable candidate.');
    }
    adaptivePromotionWriteJson($resultPath, $record);
}

/**
 * @param list<string> $argv
 * @return array{action:string,protocol:string,output:string,cases:list<string>,seconds:float,warmup:float,diagnostic:bool,resume:bool}
 * @throws \InvalidArgumentException
 * @throws \RuntimeException
 */
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
    $options['protocol'] = adaptivePromotionCheckedProtocol($options['protocol']);

    return $options;
}

/**
 * @param array{id:string,protocol:string,payload_bytes:int,scenario:string,low:int,medium:int,high:int,tls?:bool,keepalive?:int,upload_bytes?:int,...<array-key,mixed>} $case
 * @param array{build:string,environment_id:string,...<string,mixed>} $identity
 * @param array{action:string,protocol:string,output:string,cases:list<string>,seconds:float,warmup:float,diagnostic:bool,resume:bool} $options
 * @throws RuntimeException
   * @throws \Throwable
 */
function adaptivePromotionRunCell(string $output, array $case, string $mode, int $trial, array $identity, array $options, string $temporary): void
{
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

        return;
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

/**
 * @param list<array{id:string,protocol:string,payload_bytes:int,scenario:string,low:int,medium:int,high:int,tls?:bool,keepalive?:int,upload_bytes?:int,...<array-key,mixed>}> $selected
 * @param array{build:string,environment_id:string,...<string,mixed>} $identity
 * @param array{action:string,protocol:string,output:string,cases:list<string>,seconds:float,warmup:float,diagnostic:bool,resume:bool} $options
 * @throws RuntimeException
 * @throws \Throwable
 */
function adaptivePromotionSchedule(string $output, array $selected, array $identity, array $options, string $temporary): void
{
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
                adaptivePromotionRunCell($output, $case, $mode, $trial, $identity, $options, $temporary);
            }
        }
    }
}

/**
 * @param list<array{id:string,protocol:string,payload_bytes:int,scenario:string,low:int,medium:int,high:int,tls?:bool,keepalive?:int,upload_bytes?:int,...<array-key,mixed>}> $selected
 * @param array{build:string,environment_id:string,...<string,mixed>} $identity
 * @param array{action:string,protocol:string,output:string,cases:list<string>,seconds:float,warmup:float,diagnostic:bool,resume:bool} $options
 * @throws RuntimeException
 * @throws \Throwable
 */
function adaptivePromotionRunSelected(string $output, array $selected, array $identity, array $options): void
{
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

        adaptivePromotionSchedule($output, $selected, $identity, $options, $temporary);
    } finally {
        $temporaryFiles = glob($temporary . '/*');
        if (is_array($temporaryFiles)) {
            foreach ($temporaryFiles as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
        if (is_dir($temporary)) {
            rmdir($temporary);
        }
    }

}

/**
 * @return array{build:string,environment_id:string}
 * @throws RuntimeException
 */
function adaptivePromotionSavedIdentity(string $path): array
{
    $identity = adaptivePromotionLoadJson($path);
    if (!is_string($identity['build'] ?? null) || !is_string($identity['environment_id'] ?? null)) {
        throw new RuntimeException('Adaptive promotion environment identity is malformed.');
    }

    return ['build' => $identity['build'], 'environment_id' => $identity['environment_id']];
}

/**
 * @throws InvalidArgumentException
 */
function adaptivePromotionValidateDurations(float $seconds, float $warmup, bool $diagnostic): void
{
    if (!is_finite($seconds)
        || !is_finite($warmup)
        || $seconds < 1
        || $warmup < 1) {
        throw new InvalidArgumentException('Durations must be finite and at least one second.');
    }
    if (!$diagnostic && ($seconds < 180 || $warmup < 30)) {
        throw new InvalidArgumentException('Sustained runs require --seconds >= 180 and --warmup >= 30.');
    }

}

/**
 * @param array<string,mixed> $identity
 * @throws RuntimeException
 */
function adaptivePromotionPrepareEvidence(string $environmentPath, array $identity, bool $resume): void
{
    if (is_file($environmentPath)) {
        if (!$resume || adaptivePromotionLoadJson($environmentPath) !== $identity) {
            throw new RuntimeException('Use a fresh output directory or --resume with exactly matching build/environment.');
        }
    } else {
        adaptivePromotionWriteJson($environmentPath, $identity);
    }

}

/**
 * @param list<string> $argv
 * @throws \InvalidArgumentException
 * @throws \RuntimeException
 * @throws \Throwable
 */
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

    $output = runwireBenchmarkOutputDirectory(rtrim($options['output'], '/'));
    $environmentPath = $output . '/' . $options['protocol'] . '-environment.json';
    if ($options['action'] === 'evaluate') {
        $report = adaptivePromotionEvaluate(
            $output,
            $options['protocol'],
            adaptivePromotionSavedIdentity($environmentPath),
        );
        $summary = array_diff_key($report, ['cases' => true]);
        fwrite(STDOUT, json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);

        return $report['performance_gate_passed'] ? 0 : 1;
    }

    adaptivePromotionValidateDurations($options['seconds'], $options['warmup'], $options['diagnostic']);

    $identity = adaptivePromotionEnvironment($options['protocol']);
    if (!is_dir($output) && !mkdir($output, 0o777, true) && !is_dir($output)) {
        throw new RuntimeException('Unable to create adaptive promotion output directory.');
    }
    adaptivePromotionPrepareEvidence($environmentPath, $identity, $options['resume']);

    adaptivePromotionRunSelected($output, $selected, $identity, $options);

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

    return $report['performance_gate_passed'] ? 0 : 1;
}

if (realpath($argv[0] ?? '') === __FILE__) {
    if (!isset($argc, $argv)) {
        throw new \RuntimeException('CLI arguments are unavailable.');
    }
    $status = adaptivePromotionMain($argv);
    if ($status !== 0) {
        throw new RuntimeException('Adaptive promotion matrix did not satisfy its gate.');
    }
}

/** @param resource $process */
function adaptivePromotionRunning(mixed $process): bool
{
    return proc_get_status($process)['running'];
}
