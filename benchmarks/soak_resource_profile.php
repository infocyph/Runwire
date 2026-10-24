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
function soakReadProcessTree(int $root): array
{
    $pending = [$root];
    $seen = [];
    $totals = ['processes' => 0, 'rss' => 0, 'virtual' => 0, 'fd' => 0];

    while ($pending !== []) {
        $pid = array_pop($pending);
        if (!is_int($pid) || $pid < 1 || isset($seen[$pid])) {
            continue;
        }
        $seen[$pid] = true;
        $proc = '/proc/' . $pid;
        if (!is_dir($proc)) {
            continue;
        }

        $statusPath = $proc . '/status';
        $fdPath = $proc . '/fd';
        if (!is_readable($statusPath) || !is_readable($fdPath)) {
            throw new RuntimeException('Cannot read required Linux worker resource metrics.');
        }
        $status = file_get_contents($statusPath);
        $fds = scandir($fdPath);
        if (!is_string($status) || !is_array($fds)
            || preg_match('/^VmRSS:\s+(\d+)\s+kB/m', $status, $rss) !== 1
            || preg_match('/^VmSize:\s+(\d+)\s+kB/m', $status, $virtual) !== 1) {
            throw new RuntimeException('Linux worker resource metrics are incomplete.');
        }

        ++$totals['processes'];
        $totals['rss'] += (int) $rss[1] * 1_024;
        $totals['virtual'] += (int) $virtual[1] * 1_024;
        $totals['fd'] += count($fds) - 2;

        $childrenPath = $proc . '/task/' . $pid . '/children';
        if (is_readable($childrenPath)) {
            $children = file_get_contents($childrenPath);
            if (!is_string($children)) {
                throw new RuntimeException('Cannot inspect Linux worker descendants.');
            }
            foreach (preg_split('/\s+/', trim($children)) ?: [] as $child) {
                if ($child !== '' && ctype_digit($child)) {
                    $pending[] = (int) $child;
                }
            }
        }
    }

    return $totals;
}

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
            time_nanosleep($interval, 0);
        }
        if ($total === 0) {
            throw new RuntimeException('No resource samples collected.');
        }
    } finally {
        fclose($output);
    }
}

/** @param list<int|float> $values */
function soakResourceMedian(array $values): float
{
    if ($values === []) {
        throw new InvalidArgumentException('Cannot calculate an empty resource sample median.');
    }
    sort($values, SORT_NUMERIC);
    $at = intdiv(count($values), 2);

    return count($values) % 2 === 0
        ? ((float) $values[$at - 1] + (float) $values[$at]) / 2
        : (float) $values[$at];
}

/**
 * @param list<array{elapsed:float,processes:int,rss:int,virtual:int,fd:int}> $samples
 * @return array<string, int|float|bool|string>
 */
function soakAnalyzeSamples(array $samples, float $minimumSeconds = 1740.0): array
{
    if ($minimumSeconds <= 0 || !is_finite($minimumSeconds)) {
        throw new InvalidArgumentException('Soak minimum duration must be finite and positive.');
    }
    $duration = $samples === [] ? 0.0 : $samples[count($samples) - 1]['elapsed'];
    $early = [];
    $late = [];
    $maxRss = 0;
    $maxFd = 0;
    $maxProcesses = 0;
    $lastTime = -1.0;
    foreach ($samples as $sample) {
        if (!is_finite($sample['elapsed']) || $sample['elapsed'] <= $lastTime
            || $sample['processes'] < 1 || $sample['rss'] <= 0 || $sample['virtual'] <= 0 || $sample['fd'] < 0) {
            throw new RuntimeException('Malformed or nonmonotonic resource sample.');
        }
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

    $enough = $duration >= $minimumSeconds && count($early) >= 8 && count($late) >= 8;
    $earlyRss = $early === [] ? 0.0 : soakResourceMedian(array_column($early, 'rss'));
    $lateRss = $late === [] ? 0.0 : soakResourceMedian(array_column($late, 'rss'));
    $earlyFd = $early === [] ? 0.0 : soakResourceMedian(array_column($early, 'fd'));
    $lateFd = $late === [] ? 0.0 : soakResourceMedian(array_column($late, 'fd'));
    $earlyProcesses = $early === [] ? 0.0 : soakResourceMedian(array_column($early, 'processes'));
    $lateProcesses = $late === [] ? 0.0 : soakResourceMedian(array_column($late, 'processes'));
    $rssLimit = $earlyRss * 1.15 + 33_554_432;
    $fdLimit = $earlyFd + 8;
    $passed = $enough && $lateRss <= $rssLimit && $lateFd <= $fdLimit
        && $lateProcesses <= $earlyProcesses + 1;

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

/** @return list<array{elapsed:float,processes:int,rss:int,virtual:int,fd:int}> */
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
            if (count($row) !== 5 || !is_numeric($row[0]) || !ctype_digit($row[1])
                || !ctype_digit($row[2]) || !ctype_digit($row[3]) || !ctype_digit($row[4])) {
                throw new RuntimeException('Invalid resource sample CSV row.');
            }
            $samples[] = [
                'elapsed' => (float) $row[0],
                'processes' => (int) $row[1],
                'rss' => (int) $row[2],
                'virtual' => (int) $row[3],
                'fd' => (int) $row[4],
            ];
        }

        return $samples;
    } finally {
        fclose($f);
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
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
