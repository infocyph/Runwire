<?php

declare(strict_types=1);

/**
 * Accept only an existing regular local CLI evidence file. This intentionally
 * permits an operator-selected source, but no stream-wrapper network or
 * virtual-file source can cross the read boundary.
 *
 * @throws \RuntimeException
 */
function runwireBenchmarkReadablePath(string $path): string
{
    if ($path === '' || strlen($path) > 4096 || str_contains($path, "\0") || str_contains($path, '://')) {
        throw new RuntimeException('Benchmark evidence must be a local filesystem path.');
    }

    $canonical = realpath($path);
    if ($canonical === false || !is_file($canonical) || !is_readable($canonical)) {
        throw new RuntimeException('Benchmark evidence must be an existing readable regular file.');
    }

    return $canonical;
}

/**
 * Restrict output to an existing local writable parent without symlink leaves.
 *
 * @throws \RuntimeException
 */
function runwireBenchmarkOutputPath(string $path): string
{
    if ($path === '' || strlen($path) > 4096 || str_contains($path, "\0") || str_contains($path, '://')) {
        throw new RuntimeException('Benchmark output must be a local filesystem path.');
    }

    $name = basename($path);
    $parent = realpath(dirname($path));
    if ($name === '.' || $name === '..' || $parent === false
        || !is_dir($parent) || !is_writable($parent)) {
        throw new RuntimeException('Benchmark output requires an existing writable parent directory.');
    }

    $resolved = $parent . DIRECTORY_SEPARATOR . $name;
    if (is_link($resolved)) {
        throw new RuntimeException('Benchmark output must not follow a symlink.');
    }
    if (file_exists($resolved) && !is_file($resolved)) {
        throw new RuntimeException('Benchmark output must be a regular file.');
    }

    return $resolved;
}

/**
 * Bound a caller-provided sampling interval before a blocking sleep.
 *
 * @throws \InvalidArgumentException
 */
function runwireBenchmarkSampleInterval(int $interval): int
{
    if ($interval < 1 || $interval > 60) {
        throw new InvalidArgumentException('Benchmark sample interval must be 1..60 seconds.');
    }

    return $interval;
}

/**
 * Sleep for a bounded sample interval in one-second chunks.
 *
 * A subprocess interrupted by a signal can resume its loop promptly instead
 * of committing to a single potentially long blocking system call.
 *
 * @throws \InvalidArgumentException
 */
function runwireBenchmarkSleepInterval(int $interval): bool
{
    $interval = runwireBenchmarkSampleInterval($interval);
    while ($interval > 0) {
        $result = time_nanosleep(1, 0);
        if ($result === false) {
            return false;
        }
        if (is_array($result)) {
            return true;
        }
        --$interval;
    }

    return true;
}
