<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/benchmarks/cli_local_paths.php';

it('accepts regular local benchmark evidence and refuses stream wrappers', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'runwire-cli-source-');
    \PHPUnit\Framework\Assert::assertIsString($path);
    try {
        expect(runwireBenchmarkReadablePath($path))->toBe(realpath($path));
        expect(fn(): string => runwireBenchmarkReadablePath('php://filter/resource=' . $path))
            ->toThrow(RuntimeException::class);
        expect(fn(): string => runwireBenchmarkReadablePath(sys_get_temp_dir()))
            ->toThrow(RuntimeException::class);
        expect(fn(): string => runwireBenchmarkReadablePath($path . '.missing'))
            ->toThrow(RuntimeException::class);
    } finally {
        unlink($path);
    }
});

it('rejects symlinked output paths and unbounded benchmark sampling delays', function (): void {
    $directory = sys_get_temp_dir() . '/runwire-cli-output-' . bin2hex(random_bytes(5));
    \PHPUnit\Framework\Assert::assertTrue(mkdir($directory, 0700));
    $target = $directory . '/target.json';
    $link = $directory . '/linked.json';
    file_put_contents($target, '{}');
    $fifo = $directory . '/named-pipe';
    \PHPUnit\Framework\Assert::assertTrue(posix_mkfifo($fifo, 0600));
    \PHPUnit\Framework\Assert::assertTrue(symlink($target, $link));
    try {
        expect(runwireBenchmarkOutputPath($directory . '/report.json'))->toBe($directory . '/report.json');
        expect(fn(): string => runwireBenchmarkOutputPath($link))->toThrow(RuntimeException::class);
        expect(fn(): string => runwireBenchmarkOutputPath($directory))->toThrow(RuntimeException::class);
        expect(fn(): string => runwireBenchmarkOutputPath($fifo))->toThrow(RuntimeException::class);
        expect(fn(): string => runwireBenchmarkOutputPath('php://memory'))->toThrow(RuntimeException::class);
        expect(runwireBenchmarkSampleInterval(1))->toBe(1)
            ->and(runwireBenchmarkSampleInterval(60))->toBe(60);
        expect(fn(): int => runwireBenchmarkSampleInterval(61))->toThrow(InvalidArgumentException::class);
    } finally {
        unlink($fifo);
        unlink($link);
        unlink($target);
        rmdir($directory);
    }
});


it('validates CLI output directories while preserving intended local paths', function (): void {
    $root = sys_get_temp_dir() . '/runwire-dir-validation-' . bin2hex(random_bytes(5));
    expect(mkdir($root, 0700))->toBeTrue();
    $linked = $root . '/link';
    expect(symlink(sys_get_temp_dir(), $linked))->toBeTrue();
    try {
        expect(runwireBenchmarkOutputDirectory($root . '/nested/new'))->toBe($root . '/nested/new');
        expect(fn(): string => runwireBenchmarkOutputDirectory($root . '/../escape'))
            ->toThrow(RuntimeException::class);
        expect(fn(): string => runwireBenchmarkOutputDirectory($linked . '/file'))
            ->toThrow(RuntimeException::class);
        expect(fn(): string => runwireBenchmarkOutputDirectory('phar://unsafe.phar'))
            ->toThrow(RuntimeException::class);
        expect(fn(): string => runwireBenchmarkOutputDirectory($root . '/./duplicate'))
            ->toThrow(RuntimeException::class);
    } finally {
        unlink($linked);
        rmdir($root);
    }
});

it('uses a bounded one-second sampling sleep and refuses unbounded intervals', function (): void {
    expect(fn(): bool => runwireBenchmarkSleepInterval(0))->toThrow(InvalidArgumentException::class);
    expect(fn(): bool => runwireBenchmarkSleepInterval(61))->toThrow(InvalidArgumentException::class);
    expect(runwireBenchmarkSleepInterval(1))->toBeTrue();
});


it('verifies a canonical Composer autoload path before executing selected source', function (): void {
    $root = sys_get_temp_dir() . '/runwire-autoload-root-' . bin2hex(random_bytes(5));
    $vendor = $root . '/vendor';
    expect(mkdir($root, 0700))->toBeTrue()
        ->and(mkdir($vendor, 0700))->toBeTrue();

    $autoload = $vendor . '/autoload.php';
    $outside = tempnam(sys_get_temp_dir(), 'runwire-autoload-outside-');
    \PHPUnit\Framework\Assert::assertIsString($outside);
    file_put_contents($autoload, "<?php\n");

    try {
        runwireBenchmarkCheckAutoload($root);
        expect(fn() => runwireBenchmarkCheckAutoload($root . '/..'))->toThrow(RuntimeException::class);
        unlink($autoload);
        expect(symlink($outside, $autoload))->toBeTrue();
        expect(fn() => runwireBenchmarkCheckAutoload($root))->toThrow(RuntimeException::class);
    } finally {
        unlink($autoload);
        unlink($outside);
        rmdir($vendor);
        rmdir($root);
    }
});


it('loads a validated source checkout Composer entry point from the CLI boundary', function (): void {
    $root = sys_get_temp_dir() . '/runwire-autoload-exec-' . bin2hex(random_bytes(5));
    $vendor = $root . '/vendor';
    expect(mkdir($root, 0700))->toBeTrue()
        ->and(mkdir($vendor, 0700))->toBeTrue();

    $name = 'RUNWIRE_AUTOLOAD_' . strtoupper(bin2hex(random_bytes(6)));
    file_put_contents($vendor . '/autoload.php', '<?php $GLOBALS[' . var_export($name, true) . '] = "loaded";');

    try {
        runwireBenchmarkLoadSelectedComposer($root);
        expect($GLOBALS[$name] ?? null)->toBe('loaded');
    } finally {
        unset($GLOBALS[$name]);
        unlink($vendor . '/autoload.php');
        rmdir($vendor);
        rmdir($root);
    }
});
