<?php

declare(strict_types=1);

use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Network\Connection;
use Infocyph\Runwire\Network\Dialer;

require_once dirname(__DIR__) . '/vendor/autoload.php';

/** @param list<float> $sorted */
function u03Percentile(array $sorted, float $fraction): float
{
    return round($sorted[(int) floor((count($sorted) - 1) * $fraction)], 4);
}

/** @param array<string, int> $usage */
function u03CpuMilliseconds(array $usage): float
{
    return (float) (($usage['ru_utime.tv_sec'] ?? 0) * 1_000
        + ($usage['ru_utime.tv_usec'] ?? 0) / 1_000
        + ($usage['ru_stime.tv_sec'] ?? 0) * 1_000
        + ($usage['ru_stime.tv_usec'] ?? 0) / 1_000);
}

/** Read the Linux process resident set from the kernel, in bytes. */
function u03ResidentBytes(): int
{
    $status = file_get_contents('/proc/self/status');
    if (!is_string($status) || preg_match('/^VmRSS:\s+(\d+)\s+kB/m', $status, $matches) !== 1) {
        throw new RuntimeException('U03 benchmark requires Linux /proc/self/status RSS evidence.');
    }

    return (int) $matches[1] * 1_024;
}

/** @return array<string, float|int|bool> */
function u03DialTrial(bool $overlap, int $count): array
{
    $listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if (!is_resource($listener)) {
        throw new RuntimeException($error);
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
    $startCpu = getrusage();
    if (!is_array($startCpu)) {
        throw new RuntimeException('CPU usage is unavailable.');
    }
    $start = hrtime(true);
    $launch = static function () use ($dialer, $address, $loop, &$elapsed, &$finished, &$failed, $count, $overlap): void {
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
            $elapsed[] = (hrtime(true) - $began) / 1_000_000;
            ++$finished;
            if (!$overlap || $finished === $count) {
                $loop->stop();
            }
        });
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

    $accepted = 0;
    for ($i = 0; $i < $count; ++$i) {
        $peer = stream_socket_accept($listener, 1.0);
        if (!is_resource($peer)) {
            break;
        }
        ++$accepted;
        fclose($peer);
    }
    fclose($listener);
    $dialer->close();
    $endCpu = getrusage();
    if (!is_array($endCpu)) {
        throw new RuntimeException('Could not read final CPU usage.');
    }

    sort($elapsed, SORT_NUMERIC);
    $seconds = (hrtime(true) - $start) / 1_000_000_000;
    $diagnostics = $loop->diagnostics();

    return [
        'submitted' => $count,
        'completed' => $finished,
        'failed' => $failed,
        'accepted' => $accepted,
        'elapsed_s' => round($seconds, 5),
        'successful_connects_per_s' => round(($finished - $failed) / max(0.000001, $seconds), 2),
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
    'runtime_build' => getenv('RUNWIRE_RUNTIME_BUILD') ?: 'local',
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
