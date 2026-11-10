<?php

declare(strict_types=1);

/**
 * Accept only an existing regular local CLI evidence file. This intentionally
 * permits an operator-selected source, but no stream-wrapper network or
 * virtual-file source can cross the read boundary.
 * The local source is deliberately selected by an authorized CLI operator.
 *
 * @throws \RuntimeException
 * @psalm-taint-specialize
 * @psalm-taint-escape file
 * @psalm-taint-escape ssrf
 * @psalm-taint-escape url_component
 * @psalm-taint-escape url_path
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
 * Restrict a developer-selected output to a writable local regular-file path,
 * without following symlinks or allowing network stream wrappers.
 *
 * @throws \RuntimeException
 * @psalm-taint-specialize
 * @psalm-taint-escape file
 * @psalm-taint-escape ssrf
 * @psalm-taint-escape url_component
 * @psalm-taint-escape url_path
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
 * Reject unsafe existing components before creating benchmark artifacts.
 *
 * @throws \RuntimeException
 */
function runwireBenchmarkCheckDirectoryComponent(string $current, string $part): void
{
    if ($part === '' || $part === '.' || $part === '..') {
        throw new RuntimeException('Benchmark directory traversal is not permitted.');
    }
    if (is_link($current) || (file_exists($current) && !is_dir($current))) {
        throw new RuntimeException('Benchmark directory has a symlink or non-directory component.');
    }
    if (is_dir($current) && !is_writable($current)) {
        throw new RuntimeException('Benchmark directory contains a nonwritable ancestor.');
    }
}

/**
 * Accept a developer-selected directory for new local benchmark artifacts.
 * Validate existing ancestors, reject traversal and symbolic-link redirects.
 * The returned spelling is unchanged to preserve evidence paths.
 *
 * @throws \RuntimeException
 * @psalm-taint-escape file
 * @psalm-taint-escape ssrf
 * @psalm-taint-escape url_component
 * @psalm-taint-escape url_path
 */
function runwireBenchmarkOutputDirectory(string $directory): string
{
    if ($directory === '' || strlen($directory) > 4096 || str_contains($directory, "\0")
        || str_contains($directory, '://')) {
        throw new RuntimeException('Benchmark directory must be a local path.');
    }

    $current = str_starts_with($directory, '/') ? '' : getcwd();
    if (!is_string($current)) {
        throw new RuntimeException('Cannot determine benchmark working directory.');
    }
    foreach (explode('/', trim($directory, '/')) as $part) {
        $current .= '/' . $part;
        runwireBenchmarkCheckDirectoryComponent($current, $part);
    }

    return $directory;
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

/**
 * Verify that a developer-selected checkout contains its own regular,
 * non-symlinked Composer autoload file before executing that checkout's code.
 *
 * @throws \RuntimeException
 */
function runwireBenchmarkCheckAutoload(string $root): void
{
    $canonical = realpath($root);
    if ($root === '' || $canonical === false || $root !== $canonical || !is_dir($root)) {
        throw new RuntimeException('Benchmark source must be a canonical local directory.');
    }

    $path = $root . '/vendor/autoload.php';
    if (realpath($path) !== $path || !is_file($path)) {
        throw new RuntimeException('Benchmark source must contain its own regular Composer autoloader.');
    }
}
