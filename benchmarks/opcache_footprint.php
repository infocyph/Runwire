<?php

declare(strict_types=1);

use Infocyph\Runwire\Coroutine\CoroutineRuntime;
use Infocyph\Runwire\Coroutine\CoroutineScope;
use Infocyph\Runwire\Http\Enum\ProtocolVersion;
use Infocyph\Runwire\Http\Headers;
use Infocyph\Runwire\Http\HttpRequest;
use Infocyph\Runwire\Http\Internal\BufferedRequestBody;
use Infocyph\Runwire\Http\Internal\CallbackResponseWriter;
use Infocyph\Runwire\Http\ResponseWriterInterface;
use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Runtime\CoroutineRequestHandler;
use Infocyph\Runwire\Runtime\Host\RuntimeApplication;

require __DIR__ . '/../vendor/autoload.php';

/**
 * @param array<mixed> $metrics
 * @throws \UnexpectedValueException
 */
function footprintIntegerMetric(array $metrics, string $name): int
{
    $value = $metrics[$name] ?? 0;
    if (!is_int($value) || $value < 0) {
        throw new UnexpectedValueException('Invalid OPcache integer metric: ' . $name);
    }

    return $value;
}

$mode = $argv[1] ?? 'bootstrap';
if (!in_array($mode, ['bootstrap', 'lifecycle', 'coroutine'], true)) {
    throw new InvalidArgumentException(
        'Usage: php benchmarks/opcache_footprint.php <bootstrap|lifecycle|coroutine>',
    );
}

$completed = true;
if ($mode === 'lifecycle') {
    $request = footprintRequest();
    $application = new RuntimeApplication(static function (HttpRequest $request, ResponseWriterInterface $writer): void {
        unset($request);
        $writer->end('ok');
    });
    $application->handle($request, footprintWriter());
    $completed = $request->context->completed();
} elseif ($mode === 'coroutine') {
    $loop = new SelectLoop();
    $runtime = new CoroutineRuntime($loop);
    $handler = new CoroutineRequestHandler(
        $runtime,
        static function (HttpRequest $request, ResponseWriterInterface $writer, CoroutineScope $scope): void {
            unset($request);
            $scope->yieldNow();
            $writer->end('ok');
        },
    );
    $handler->attachLoop($loop);
    $request = footprintRequest();
    $application = new RuntimeApplication($handler);
    $application->handle($request, footprintWriter());
    $loop->run();
    $completed = $request->context->completed();
}

$source = realpath(__DIR__ . '/../src');
if (!is_string($source)) {
    throw new RuntimeException('Unable to resolve Runwire source directory.');
}
$prefix = $source . DIRECTORY_SEPARATOR;
$relative = static function (string $path) use ($prefix): ?string {
    $real = realpath($path);
    if (!is_string($real) || !str_starts_with($real, $prefix)) {
        return null;
    }

    return str_replace(DIRECTORY_SEPARATOR, '/', substr($real, strlen($prefix)));
};

$included = [];
foreach (get_included_files() as $path) {
    $file = $relative($path);
    if ($file !== null) {
        $included[] = $file;
    }
}
sort($included, SORT_STRING);

$status = function_exists('opcache_get_status') ? opcache_get_status(true) : false;
$cached = [];
$cachedBytes = 0;
if (is_array($status)) {
    $scripts = $status['scripts'] ?? [];
    if (!is_array($scripts)) {
        throw new UnexpectedValueException('OPcache scripts inventory is malformed.');
    }
    foreach ($scripts as $path => $script) {
        if (!is_string($path) || !is_array($script)) {
            throw new UnexpectedValueException('OPcache script evidence has an invalid entry.');
        }
        $file = $relative($path);
        if ($file === null) {
            continue;
        }
        $cached[] = $file;
        $cachedBytes += footprintIntegerMetric($script, 'memory_consumption');
    }
}
sort($cached, SORT_STRING);

$sourceFiles = [];
$sourceBytes = 0;
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
);
foreach ($iterator as $file) {
    if (!$file instanceof SplFileInfo || !$file->isFile() || $file->getExtension() !== 'php') {
        continue;
    }
    $sourceFiles[] = str_replace(
        DIRECTORY_SEPARATOR,
        '/',
        substr($file->getPathname(), strlen($prefix)),
    );
    $sourceBytes += $file->getSize();
}
sort($sourceFiles, SORT_STRING);

$memory = is_array($status) && is_array($status['memory_usage'] ?? null) ? $status['memory_usage'] : [];
$statistics = is_array($status) && is_array($status['opcache_statistics'] ?? null) ? $status['opcache_statistics'] : [];
$interned = is_array($status) && is_array($status['interned_strings_usage'] ?? null) ? $status['interned_strings_usage'] : [];

$result = [
    'mode' => $mode,
    'php_version' => PHP_VERSION,
    'opcache_enabled' => is_array($status),
    'correctness_passed' => $completed,
    'source_php_files' => count($sourceFiles),
    'source_bytes' => $sourceBytes,
    'included_runwire_files' => count($included),
    'cached_runwire_files' => count($cached),
    'runwire_script_cache_memory_bytes' => $cachedBytes,
    'included_runwire_scripts' => $included,
    'cached_runwire_scripts' => $cached,
    'opcache' => [
        'num_cached_scripts' => footprintIntegerMetric($statistics, 'num_cached_scripts'),
        'num_cached_keys' => footprintIntegerMetric($statistics, 'num_cached_keys'),
        'max_cached_keys' => footprintIntegerMetric($statistics, 'max_cached_keys'),
        'cache_full' => (bool) ($status['cache_full'] ?? false),
        'oom_restarts' => footprintIntegerMetric($statistics, 'oom_restarts'),
        'hash_restarts' => footprintIntegerMetric($statistics, 'hash_restarts'),
        'manual_restarts' => footprintIntegerMetric($statistics, 'manual_restarts'),
        'used_memory_bytes' => footprintIntegerMetric($memory, 'used_memory'),
        'free_memory_bytes' => footprintIntegerMetric($memory, 'free_memory'),
        'wasted_memory_bytes' => footprintIntegerMetric($memory, 'wasted_memory'),
        'interned_used_memory_bytes' => footprintIntegerMetric($interned, 'used_memory'),
        'interned_free_memory_bytes' => footprintIntegerMetric($interned, 'free_memory'),
        'interned_strings' => footprintIntegerMetric($interned, 'number_of_strings'),
    ],
];

fwrite(
    STDOUT,
    json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL,
);
if (!$completed) {
    throw new RuntimeException('Runwire footprint workload did not complete successfully.');
}

/**
 * @throws \OverflowException
 */
function footprintRequest(): HttpRequest
{
    return new HttpRequest(
        method: 'GET',
        target: '/footprint',
        version: ProtocolVersion::HTTP_1_1,
        headers: new Headers(),
        body: new BufferedRequestBody(''),
    );
}

/**
 * @throws \InvalidArgumentException
 */
function footprintWriter(): ResponseWriterInterface
{
    return new CallbackResponseWriter(
        static function (): void {},
        static function (): void {},
        static function (): void {},
        1_024,
    );
}
