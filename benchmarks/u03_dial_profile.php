<?php

declare(strict_types=1);

require_once __DIR__ . '/process_usage.php';

use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Network\Connection;
use Infocyph\Runwire\Network\Dialer;

require_once dirname(__DIR__) . '/vendor/autoload.php';

/** @param list<float> $sorted */
function u03Percentile(array $sorted, float $fraction): float
{
    return round($sorted[(int) floor((float) (count($sorted) - 1) * $fraction)], 4);
}

/** @param array<string, int> $usage */
function u03CpuMilliseconds(array $usage): float
{
    return (float) ($usage['ru_utime.tv_sec'] ?? 0) * 1_000.0
        + (float) ($usage['ru_utime.tv_usec'] ?? 0) / 1_000.0
        + (float) ($usage['ru_stime.tv_sec'] ?? 0) * 1_000.0
        + (float) ($usage['ru_stime.tv_usec'] ?? 0) / 1_000.0;
}

/**
 * Read the Linux process resident set from the kernel, in bytes.
 * @throws \RuntimeException
 */
function u03ResidentBytes(): int
{
    $status = file_get_contents('/proc/self/status');
    if (!is_string($status) || preg_match('/^VmRSS:\s+(\d+)\s+kB/m', $status, $matches) !== 1) {
        throw new RuntimeException('U03 benchmark requires Linux /proc/self/status RSS evidence.');
    }

    return (int) $matches[1] * 1_024;
}

/**
 * @param list<float> $elapsed
 * @throws Throwable
 */
function u03Launch(Dialer $dialer, string $address, SelectLoop $loop, array &$elapsed, int &$finished, int &$failed, int $count, bool $overlap): void
{
    $began = hrtime(true);
    $dialer->connect('tcp://' . $address, static function (?Connection $connection, ?Throwable $error) use (
        $loop,
        &$elapsed,
        &$finished,
        &$failed,
        $began,
        $count,
        $overlap,
    ): void {
        if ($error !== null || $connection === null) {
            ++$failed;
        }
        $connection?->abort();
        $elapsed[] = (float) (hrtime(true) - $began) / 1_000_000.0;
        ++$finished;
        if (!$overlap || $finished === $count) {
            $loop->stop();
        }
    });
}

/** @param resource $listener */
function u03Accept(mixed $listener, int $count): int
{
    $accepted = 0;
    for ($i = 0; $i < $count; ++$i) {
        $peer = stream_socket_accept($listener, 1.0);
        if (!is_resource($peer)) {
            break;
        }
        ++$accepted;
        fclose($peer);
    }

    return $accepted;
}

/**
 * @return array{submitted:int,completed:int,failed:int,accepted:int,elapsed_s:float,successful_connects_per_s:float,p99_ms:float,cpu_parent_ms:float,rss_bytes:int,watchers_after:int,timers_after:int,pending_after:int,correctness_passed:bool}
 * @throws \Throwable
 */
function u03DialTrial(bool $overlap, int $count): array
{
    $listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if (!is_resource($listener)) {
        u03SocketFailure($error);
    }
    $address = stream_socket_get_name($listener, false);
    if (!is_string($address)) {
        fclose($listener);

        throw new RuntimeException('TCP dial profile has no listener address.');
    }

    $loop = new SelectLoop();
    $dialer = new Dialer($loop, $count + 1);
    $elapsed = [];
    $finished = 0;
    $failed = 0;
    $startCpu = runwireBenchmarkUsage();
    $start = hrtime(true);
    $launch = static function () use ($dialer, $address, $loop, &$elapsed, &$finished, &$failed, $count, $overlap): void {
        u03Launch($dialer, $address, $loop, $elapsed, $finished, $failed, $count, $overlap);
    };

    if ($overlap) {
        for ($i = 0; $i < $count; ++$i) {
            $launch();
        }
        $loop->run();
    } else {
        for ($i = 0; $i < $count; ++$i) {
            $launch();
            $loop->run();
        }
    }

    $accepted = u03Accept($listener, $count);
    fclose($listener);
    $dialer->close();
    $endCpu = runwireBenchmarkUsage();

    sort($elapsed, SORT_NUMERIC);
    $seconds = (float) (hrtime(true) - $start) / 1_000_000_000.0;
    $diagnostics = $loop->diagnostics();

    return [
        'submitted' => $count,
        'completed' => $finished,
        'failed' => $failed,
        'accepted' => $accepted,
        'elapsed_s' => round($seconds, 5),
        'successful_connects_per_s' => round((float) ($finished - $failed) / max(0.000001, $seconds), 2),
        'p99_ms' => $elapsed !== [] ? u03Percentile($elapsed, 0.99) : 0.0,
        'cpu_parent_ms' => round(max(0, u03CpuMilliseconds($endCpu) - u03CpuMilliseconds($startCpu)), 3),
        'rss_bytes' => u03ResidentBytes(),
        'watchers_after' => $diagnostics->readWatchers + $diagnostics->writeWatchers,
        'timers_after' => $diagnostics->timersActive,
        'pending_after' => $dialer->pendingCount(),
        'correctness_passed' => $finished === $count && $failed === 0 && $accepted === $count
            && $diagnostics->readWatchers === 0 && $diagnostics->writeWatchers === 0
            && $diagnostics->timersActive === 0 && $dialer->pendingCount() === 0,
    ];
}

$sequential = u03DialTrial(false, 24);
$overlap = u03DialTrial(true, 24);
$profile = [
    'schema' => 1,
    'runtime_build' => (($build = getenv('RUNWIRE_RUNTIME_BUILD')) === false || $build === '' || $build === '0') ? 'local' : $build,
    'php' => PHP_VERSION,
    'method' => 'real local TCP verified connection handoff on a single machine',
    'real_socket_io' => true,
    'rss_source' => '/proc/self/status VmRSS (resident set, not peak)',
    'sequential' => $sequential,
    'overlap' => $overlap,
    'correctness_passed' => $sequential['correctness_passed'] && $overlap['correctness_passed'],
];
if (fwrite(STDOUT, json_encode($profile, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . "\n") === false) {
    throw new RuntimeException('Unable to record U03 benchmark evidence.');
}
if (!$profile['correctness_passed']) {
    throw new RuntimeException('U03 dial profiling reported a failed connection or leaked resource.');
}

/** @throws RuntimeException */
function u03SocketFailure(?string $error): never
{
    throw new RuntimeException($error ?? 'Socket operation failed.');
}
