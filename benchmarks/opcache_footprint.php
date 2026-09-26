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

$mode = $argv[1] ?? 'bootstrap';
if (!in_array($mode, ['bootstrap', 'lifecycle', 'coroutine'], true)) {
    fwrite(STDERR, "Usage: php benchmarks/opcache_footprint.php <bootstrap|lifecycle|coroutine>\n");
    exit(2);
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
    foreach ($status['scripts'] ?? [] as $path => $script) {
        $file = $relative((string) $path);
        if ($file === null) {
            continue;
        }
        $cached[] = $file;
        $cachedBytes += (int) ($script['memory_consumption'] ?? 0);
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

$memory = is_array($status) ? ($status['memory_usage'] ?? []) : [];
$statistics = is_array($status) ? ($status['opcache_statistics'] ?? []) : [];
$interned = is_array($status) ? ($status['interned_strings_usage'] ?? []) : [];

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
        'num_cached_scripts' => (int) ($statistics['num_cached_scripts'] ?? 0),
        'num_cached_keys' => (int) ($statistics['num_cached_keys'] ?? 0),
        'max_cached_keys' => (int) ($statistics['max_cached_keys'] ?? 0),
        'cache_full' => (bool) ($status['cache_full'] ?? false),
        'oom_restarts' => (int) ($statistics['oom_restarts'] ?? 0),
        'hash_restarts' => (int) ($statistics['hash_restarts'] ?? 0),
        'manual_restarts' => (int) ($statistics['manual_restarts'] ?? 0),
        'used_memory_bytes' => (int) ($memory['used_memory'] ?? 0),
        'free_memory_bytes' => (int) ($memory['free_memory'] ?? 0),
        'wasted_memory_bytes' => (int) ($memory['wasted_memory'] ?? 0),
        'interned_used_memory_bytes' => (int) ($interned['used_memory'] ?? 0),
        'interned_free_memory_bytes' => (int) ($interned['free_memory'] ?? 0),
        'interned_strings' => (int) ($interned['number_of_strings'] ?? 0),
    ],
];

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), PHP_EOL;
exit($completed ? 0 : 1);

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

function footprintWriter(): ResponseWriterInterface
{
    return new CallbackResponseWriter(
        static function (): void {},
        static function (): void {},
        static function (): void {},
        1_024,
    );
}
