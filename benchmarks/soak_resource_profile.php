<?php

declare(strict_types=1);

/**
 * Sample Linux process RSS, virtual memory and descriptor counts externally.
 * No instrumentation runs inside the measured Runwire server.
 */
/**
 * Collect summed VmRSS, VmSize and descriptor counts from a root PID and
 * its descendant workers. RSS is a summed process metric, NOT proportional
 * set size (PSS): shared mappings may be counted once per process.
 *
 * @return array{processes:int,rss:int,virtual:int,fd:int}
 */
/**
 * Read a procfs file across process exit without emitting an expected
 * disappearing-PID warning into the GitHub worker's strict error pipeline.
 * Live missing metrics are still rejected by the caller.
 */
function soakProcfsRead(string $path): string|false
{
    set_error_handler(static fn(): bool => true);

    try {
        return file_get_contents($path);
    } finally {
        restore_error_handler();
    }
}

/** @return array<int, string>|false */
function soakProcfsEntries(string $path): array|false
{
    set_error_handler(static fn(): bool => true);

    try {
        return scandir($path);
    } finally {
        restore_error_handler();
    }
}

/** @throws RuntimeException */
function soakProcessStatus(int $pid, int $root): ?string
{
    $proc = '/proc/' . $pid;
    if (!is_dir($proc)) {
        if ($pid === $root) {
            throw new RuntimeException('Runwire root process vanished during sampling.');
        }

        return null;
    }
    $status = soakProcfsRead($proc . '/status');
    for ($attempt = 0; $attempt < 3 && !is_string($status); ++$attempt) {
        if (time_nanosleep(0, 2_000_000) === false) {
            throw new RuntimeException('Unable to retry Linux process metrics after a transient exit.');
        }
        $status = soakProcfsRead($proc . '/status');
    }
    if (!is_string($status)) {
        return soakMissingProcessStatus($pid, $root);
    }
    if ($pid !== $root && preg_match('/^State:\s+Z\b/m', $status) === 1) {
        return null;
    }

    return $status;
}

/** @throws RuntimeException */
function soakMissingProcessStatus(int $pid, int $root): null
{
    $stat = soakProcfsRead('/proc/' . $pid . '/stat');
    if ($pid !== $root && (!is_string($stat) || preg_match('/^\d+\s+\(.*\)\s+[ZXx]\s/s', $stat) === 1)) {
        return null;
    }

    throw new RuntimeException('Cannot read required Linux worker resource metrics.');
}

/**
 * @return array<int,string>|null Null denotes a confirmed retired descendant.
 * @throws RuntimeException
 */
function soakProcessDescriptors(int $pid, int $root): ?array
{
    $proc = '/proc/' . $pid;
    $fds = soakProcfsEntries($proc . '/fd');
    if (is_array($fds)) {
        return $fds;
    }
    // Retry transient procfs teardown, while keeping the root mandatory.
    for ($attempt = 0; $attempt < 3; ++$attempt) {
        $latestStatus = soakProcfsRead($proc . '/status');
        if ($pid !== $root && (!is_dir($proc)
            || (is_string($latestStatus) && preg_match('/^State:\s+(?:Z|X|x)\b/m', $latestStatus) === 1))) {
            return null;
        }
        if (time_nanosleep(0, 2_000_000) === false) {
            throw new RuntimeException('Unable to retry Linux process metrics after a transient exit.');
        }
        $fds = soakProcfsEntries($proc . '/fd');
        if (is_array($fds)) {
            return $fds;
        }
    }

    return soakMissingProcessDescriptors($pid, $root);
}

/** @throws RuntimeException */
function soakMissingProcessDescriptors(int $pid, int $root): null
{
    $latestStatus = soakProcfsRead('/proc/' . $pid . '/status');
    if ($pid !== $root && !is_string($latestStatus)) {
        return null;
    }

    throw new RuntimeException(sprintf(
        'Cannot read required Linux worker resource metrics: pid=%d state=%s.',
        $pid,
        is_string($latestStatus) && preg_match('/^State:\s+([^\n]+)/m', $latestStatus, $state) === 1
            ? trim($state[1]) : 'unreadable',
    ));
}

/**
 * @return list<int>
 * @throws RuntimeException
 */
function soakProcessChildren(int $pid, int $root): array
{
    $proc = '/proc/' . $pid;
    $path = $proc . '/task/' . $pid . '/children';
    if (!is_readable($path)) {
        return [];
    }
    $children = soakProcfsRead($path);
    if (!is_string($children)) {
        if ($pid !== $root && !is_dir($proc)) {
            return [];
        }

        throw new RuntimeException('Cannot inspect Linux worker descendants.');
    }
    $tokens = preg_split('/\s+/', trim($children));
    $result = [];
    foreach ($tokens === false ? [] : $tokens as $child) {
        if ($child !== '' && ctype_digit($child)) {
            $result[] = (int) $child;
        }
    }

    return $result;
}

/**
 * @return array{rss:int,virtual:int}
 * @throws RuntimeException
 */
function soakProcessMemory(string $status): array
{
    if (preg_match('/^VmRSS:\s+(\d+)\s+kB/m', $status, $rss) !== 1
        || preg_match('/^VmSize:\s+(\d+)\s+kB/m', $status, $virtual) !== 1) {
        throw new RuntimeException('Linux worker resource metrics are incomplete.');
    }

    return ['rss' => (int) $rss[1] * 1_024, 'virtual' => (int) $virtual[1] * 1_024];
}

/**
 * Collect summed RSS, virtual memory and descriptors; shared mappings may be counted per process.
 * @return array{processes:int,rss:int,virtual:int,fd:int}
 * @throws RuntimeException
 */
function soakReadProcessTree(int $root): array
{
    $pending = [$root];
    $seen = [];
    $totals = ['processes' => 0, 'rss' => 0, 'virtual' => 0, 'fd' => 0];
    while ($pending !== []) {
        $pid = array_pop($pending);
        if ($pid < 1 || isset($seen[$pid])) {
            continue;
        }
        $seen[$pid] = true;
        $status = soakProcessStatus($pid, $root);
        if ($status === null) {
            continue;
        }
        $fds = soakProcessDescriptors($pid, $root);
        if ($fds === null) {
            continue;
        }
        $memory = soakProcessMemory($status);
        ++$totals['processes'];
        $totals['rss'] += $memory['rss'];
        $totals['virtual'] += $memory['virtual'];
        $totals['fd'] += count($fds) - 2;
        array_push($pending, ...soakProcessChildren($pid, $root));
    }

    return $totals;
}

/**
 * @throws \InvalidArgumentException
 * @throws \RuntimeException
 */
function soakSampleProcess(int $pid, string $path, int $interval): void
{
    if ($pid < 1 || $interval < 1 || $interval > 60) {
        throw new InvalidArgumentException('Invalid sampler PID or sample interval.');
    }
    $output = fopen($path, 'wb');
    if (!is_resource($output)) {
        throw new RuntimeException('Unable to open resource sample file.');
    }

    try {
        fwrite($output, "elapsed_seconds,process_count,rss_bytes,virtual_bytes,fd_count\n");
        $start = hrtime(true);
        $total = 0;
        while (is_dir('/proc/' . $pid)) {
            $sample = soakReadProcessTree($pid);
            if ($sample['processes'] === 0) {
                throw new RuntimeException('Runwire server process tree disappeared during sampling.');
            }
            $elapsed = (hrtime(true) - $start) / 1_000_000_000;
            fwrite($output, sprintf(
                "%.3f,%d,%d,%d,%d\n",
                $elapsed,
                $sample['processes'],
                $sample['rss'],
                $sample['virtual'],
                $sample['fd'],
            ));
            fflush($output);
            ++$total;
            // External observer only: do not suspend a Runwire worker or event loop.
            if (time_nanosleep($interval, 0) === false) {
                throw new RuntimeException('Resource sample interval could not be observed.');
            }
        }
        if ($total === 0) {
            throw new RuntimeException('No resource samples collected.');
        }
    } finally {
        fclose($output);
    }
}

/**
 * @param list<int|float> $values
 * @throws \InvalidArgumentException
 */
function soakResourceMedian(array $values): float
{
    if ($values === []) {
        throw new InvalidArgumentException('Cannot calculate an empty resource sample median.');
    }
    sort($values, SORT_NUMERIC);
    $at = intdiv(count($values), 2);

    return count($values) % 2 === 0
        ? ((float) $values[$at - 1] + (float) $values[$at]) / 2.0
        : (float) $values[$at];
}

/**
 * @param array{elapsed:float,processes:int,rss:int,virtual:int,fd:int} $sample
 * @throws RuntimeException
 */
function soakValidateSample(array $sample, float $lastTime): void
{
    if (!is_finite($sample['elapsed']) || $sample['elapsed'] <= $lastTime
        || $sample['processes'] < 1 || $sample['rss'] <= 0 || $sample['virtual'] <= 0 || $sample['fd'] < 0) {
        throw new RuntimeException('Malformed or nonmonotonic resource sample.');
    }
}

/**
 * @param list<int|float> $values
 * @throws InvalidArgumentException
 */
function soakWindowMedian(array $values): float
{
    return $values === [] ? 0.0 : soakResourceMedian($values);
}

/**
 * @param list<array{elapsed:float,processes:int,rss:int,virtual:int,fd:int}> $samples
 * @return array{early:list<array{elapsed:float,processes:int,rss:int,virtual:int,fd:int}>,late:list<array{elapsed:float,processes:int,rss:int,virtual:int,fd:int}>,maxRss:int,maxFd:int,maxProcesses:int}
 * @throws RuntimeException
 */
function soakResourceWindows(array $samples, float $duration): array
{
    $early = [];
    $late = [];
    $maxRss = 0;
    $maxFd = 0;
    $maxProcesses = 0;
    $lastTime = -1.0;
    foreach ($samples as $sample) {
        soakValidateSample($sample, $lastTime);
        $lastTime = $sample['elapsed'];
        $maxRss = max($maxRss, $sample['rss']);
        $maxFd = max($maxFd, $sample['fd']);
        $maxProcesses = max($maxProcesses, $sample['processes']);
        if ($sample['elapsed'] >= $duration * .15 && $sample['elapsed'] <= $duration * .35) {
            $early[] = $sample;
        }
        if ($sample['elapsed'] >= $duration * .70 && $sample['elapsed'] <= $duration * .90) {
            $late[] = $sample;
        }
    }

    return compact('early', 'late', 'maxRss', 'maxFd', 'maxProcesses');
}

/**
 * @param list<array{elapsed:float,processes:int,rss:int,virtual:int,fd:int}> $samples
 * @return array<string, int|float|bool|string>
 * @throws \InvalidArgumentException
   * @throws \RuntimeException
 */
function soakAnalyzeSamples(array $samples, float $minimumSeconds = 1740.0): array
{
    if ($minimumSeconds <= 0 || !is_finite($minimumSeconds)) {
        throw new InvalidArgumentException('Soak minimum duration must be finite and positive.');
    }
    $duration = $samples === [] ? 0.0 : $samples[count($samples) - 1]['elapsed'];
    ['early' => $early, 'late' => $late, 'maxRss' => $maxRss, 'maxFd' => $maxFd, 'maxProcesses' => $maxProcesses] = soakResourceWindows($samples, $duration);

    $enough = $duration >= $minimumSeconds && count($early) >= 8 && count($late) >= 8;
    $earlyRss = soakWindowMedian(array_column($early, 'rss'));
    $lateRss = soakWindowMedian(array_column($late, 'rss'));
    $earlyFd = soakWindowMedian(array_column($early, 'fd'));
    $lateFd = soakWindowMedian(array_column($late, 'fd'));
    $earlyProcesses = soakWindowMedian(array_column($early, 'processes'));
    $lateProcesses = soakWindowMedian(array_column($late, 'processes'));
    $rssLimit = $earlyRss * 1.15 + 33_554_432.0;
    $fdLimit = $earlyFd + 8.0;
    $passed = $enough && $lateRss <= $rssLimit && $lateFd <= $fdLimit
        && $lateProcesses <= $earlyProcesses + 1.0;

    return [
        'passed' => $passed,
        'status' => !$enough ? 'insufficient_evidence' : ($passed ? 'passed' : 'resource_growth'),
        'samples' => count($samples),
        'duration_seconds' => round($duration, 3),
        'early_rss_median_bytes' => (int) round($earlyRss),
        'late_rss_median_bytes' => (int) round($lateRss),
        'rss_limit_bytes' => (int) ceil($rssLimit),
        'peak_rss_bytes' => $maxRss,
        'rss_measurement_scope' => 'summed_process_tree_vm_rss_not_pss',
        'early_process_count_median' => round($earlyProcesses, 2),
        'late_process_count_median' => round($lateProcesses, 2),
        'peak_process_count' => $maxProcesses,
        'early_fd_median' => round($earlyFd, 2),
        'late_fd_median' => round($lateFd, 2),
        'fd_limit' => round($fdLimit, 2),
        'peak_fd_count' => $maxFd,
        'php_gc_opcache_metrics_available' => false,
    ];
}

/**
 * @return list<array{elapsed:float,processes:int,rss:int,virtual:int,fd:int}>
 * @throws \RuntimeException
 */
function soakReadSamples(string $path): array
{
    $f = fopen($path, 'rb');
    if (!is_resource($f)) {
        throw new RuntimeException('Resource sample CSV is not readable.');
    }

    try {
        if (fgetcsv($f, 0, ',', '"', '') !== ['elapsed_seconds', 'process_count', 'rss_bytes', 'virtual_bytes', 'fd_count']) {
            throw new RuntimeException('Invalid resource sample CSV header.');
        }
        $samples = [];
        while (($row = fgetcsv($f, 0, ',', '"', '')) !== false) {
            $samples[] = soakResourceCsvRow($row);
        }

        return $samples;
    } finally {
        fclose($f);
    }
}

$scriptFilename = $_SERVER['SCRIPT_FILENAME'] ?? null;
if (is_string($scriptFilename) && realpath($scriptFilename) === __FILE__) {
    if (!isset($argc, $argv)) {
        throw new \RuntimeException('CLI arguments are unavailable.');
    }
    if ($argc === 5 && $argv[1] === 'sample') {
        soakSampleProcess((int) $argv[2], $argv[3], (int) $argv[4]);
    } elseif ($argc === 4 && $argv[1] === 'analyze') {
        $result = soakAnalyzeSamples(soakReadSamples($argv[2]));
        file_put_contents($argv[3], json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL);
        if (!$result['passed']) {
            throw new RuntimeException('Resource drift or sample coverage gate failed.');
        }
    } else {
        throw new InvalidArgumentException(
            'Usage: php soak_resource_profile.php sample <pid> <csv> <seconds> | analyze <csv> <json>',
        );
    }
}

/**
 * @return array{elapsed:float,processes:int,rss:int,virtual:int,fd:int}
 * @throws RuntimeException
 */
function soakResourceCsvRow(mixed $row): array
{
    if (!is_array($row) || !array_is_list($row) || count($row) !== 5 || !is_numeric($row[0])) {
        throw new RuntimeException('Invalid resource sample CSV row.');
    }

    return [
        'elapsed' => (float) $row[0],
        'processes' => soakResourceCsvInteger($row[1]),
        'rss' => soakResourceCsvInteger($row[2]),
        'virtual' => soakResourceCsvInteger($row[3]),
        'fd' => soakResourceCsvInteger($row[4]),
    ];
}

/** @throws RuntimeException */
function soakResourceCsvInteger(mixed $value): int
{
    if (!is_string($value) || !ctype_digit($value)) {
        throw new RuntimeException('Invalid resource sample CSV row.');
    }

    return (int) $value;
}
