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
