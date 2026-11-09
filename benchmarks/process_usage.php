<?php

declare(strict_types=1);

/**
 * Validate native process-usage data before using it as benchmark evidence.
 *
 * @return array{'ru_utime.tv_sec':int,'ru_utime.tv_usec':int,'ru_stime.tv_sec':int,'ru_stime.tv_usec':int,...<string,int>}
 * @throws RuntimeException
 */
function runwireBenchmarkUsageRecord(mixed $raw): array
{
    if (!is_array($raw)) {
        throw new RuntimeException('Benchmark process-usage counters are unavailable.');
    }
    $usage = [];
    foreach ($raw as $name => $value) {
        if (!is_string($name) || !is_int($value)) {
            throw new RuntimeException('Benchmark process-usage counters must be named integers.');
        }
        $usage[$name] = $value;
    }
    if (!isset($usage['ru_utime.tv_sec'], $usage['ru_utime.tv_usec'], $usage['ru_stime.tv_sec'], $usage['ru_stime.tv_usec'])) {
        throw new RuntimeException('Benchmark process-usage data lacks complete CPU counters.');
    }

    return $usage;
}

/**
 * @return array{'ru_utime.tv_sec':int,'ru_utime.tv_usec':int,'ru_stime.tv_sec':int,'ru_stime.tv_usec':int,...<string,int>}
 * @throws RuntimeException
 * @throws ValueError
 */
function runwireBenchmarkUsage(int $who = 0): array
{
    return runwireBenchmarkUsageRecord(getrusage($who));
}

/**
 * @param array<string,int> $usage
 * @throws RuntimeException
 */
function runwireBenchmarkCpuMicroseconds(array $usage): int
{
    if (!isset($usage['ru_utime.tv_sec'], $usage['ru_utime.tv_usec'], $usage['ru_stime.tv_sec'], $usage['ru_stime.tv_usec'])) {
        throw new RuntimeException('Benchmark process-usage data lacks complete CPU counters.');
    }

    return $usage['ru_utime.tv_sec'] * 1_000_000
        + $usage['ru_utime.tv_usec']
        + $usage['ru_stime.tv_sec'] * 1_000_000
        + $usage['ru_stime.tv_usec'];
}
