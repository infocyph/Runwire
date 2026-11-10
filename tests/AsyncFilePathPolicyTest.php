<?php

declare(strict_types=1);

use Infocyph\Runwire\Executor\ProcessExecutor;
use Infocyph\Runwire\Filesystem\AsyncFileSystem;
use Infocyph\Runwire\Loop\SelectLoop;

it('rejects worker-side symlink traversal and off-root paths without blocking the event loop', function (): void {
    $root = sys_get_temp_dir() . '/runwire-file-root-' . bin2hex(random_bytes(5));
    expect(mkdir($root, 0700))->toBeTrue();
    $inside = $root . '/payload';
    $outside = tempnam(sys_get_temp_dir(), 'runwire-outside-');
    \PHPUnit\Framework\Assert::assertIsString($outside);
    file_put_contents($inside, 'protected');
    file_put_contents($outside, 'unchanged');
    $denied = $root . '/permission-denied';
    file_put_contents($denied, 'locked');
    expect(chmod($denied, 0000))->toBeTrue();
    $link = $root . '/linked-file';
    $nested = $root . '/linked-dir';
    expect(symlink($outside, $link))->toBeTrue()
        ->and(symlink(sys_get_temp_dir(), $nested))->toBeTrue();

    $loop = new SelectLoop();
    $executor = new ProcessExecutor($loop, 1, 16);
    $filesystem = new AsyncFileSystem($executor, rootDirectory: $root);
    $results = [];
    $pulseCount = 0;
    $pulse = $loop->repeat(0.003, static function () use (&$pulseCount): void {
        ++$pulseCount;
    });

    try {
        $filesystem->read($inside, 9, static function (string $value, ?Throwable $error) use (&$results): void {
            $results['allowed'] = [$value, $error];
        });
        $filesystem->read($denied, 8, static function (string $value, ?Throwable $error) use (&$results): void {
            $results['permission-read'] = [$value, $error];
        });
        $filesystem->write($denied, 'unsafe', static function (int $written, ?Throwable $error) use (&$results): void {
            $results['permission-write'] = [$written, $error];
        });
        $filesystem->read($link, 12, static function (string $value, ?Throwable $error) use (&$results): void {
            $results['symlink'] = [$value, $error];
        });
        $filesystem->write($outside, 'corrupted', static function (int $written, ?Throwable $error) use (&$results): void {
            $results['outside'] = [$written, $error];
        });
        $filesystem->stat($root . '/../private', static function (array $meta, ?Throwable $error) use (&$results): void {
            $results['traversal'] = [$meta, $error];
        });
        $filesystem->rename($inside, $outside, static function (bool $moved, ?Throwable $error) use (&$results): void {
            $results['rename'] = [$moved, $error];
        });
        $filesystem->unlink($link, static function (bool $removed, ?Throwable $error) use (&$results): void {
            $results['unlink'] = [$removed, $error];
        });
        $filesystem->read($nested . '/' . basename($outside), 12, static function (string $value, ?Throwable $error) use (&$results): void {
            $results['nested'] = [$value, $error];
        });
        $executor->onDrained(static function () use ($executor, $loop, $pulse): void {
            $loop->cancel($pulse);
            $executor->close();
        });
        $loop->run();

        expect($results)->toHaveCount(9);
        $result0 = $results['allowed'] ?? throw new RuntimeException('Missing asynchronous allowed result.');
        $result1 = $results['permission-read'] ?? throw new RuntimeException('Missing asynchronous permission-read result.');
        $result2 = $results['permission-write'] ?? throw new RuntimeException('Missing asynchronous permission-write result.');
        $result3 = $results['symlink'] ?? throw new RuntimeException('Missing asynchronous symlink result.');
        $result4 = $results['outside'] ?? throw new RuntimeException('Missing asynchronous outside result.');
        $result5 = $results['traversal'] ?? throw new RuntimeException('Missing asynchronous traversal result.');
        $result6 = $results['rename'] ?? throw new RuntimeException('Missing asynchronous rename result.');
        $result7 = $results['unlink'] ?? throw new RuntimeException('Missing asynchronous unlink result.');
        $result8 = $results['nested'] ?? throw new RuntimeException('Missing asynchronous nested result.');

        expect($result0)->toBe(['protected', null])
            ->and($result1[1])->toBeInstanceOf(Throwable::class)
            ->and($result2[1])->toBeInstanceOf(Throwable::class)
            ->and($result3[1])->toBeInstanceOf(Throwable::class)
            ->and($result4[1])->toBeInstanceOf(Throwable::class)
            ->and($result5[1])->toBeInstanceOf(Throwable::class)
            ->and($result6[1])->toBeInstanceOf(Throwable::class)
            ->and($result7[1])->toBeInstanceOf(Throwable::class)
            ->and($result8[1])->toBeInstanceOf(Throwable::class)
            ->and(file_get_contents($inside))->toBe('protected')
            ->and(file_get_contents($outside))->toBe('unchanged')
            ->and(is_link($link))->toBeTrue()
            ->and($executor->diagnostics()['workers'])->toBe(0)
            ->and($loop->diagnostics()->readWatchers)->toBe(0)
            ->and($loop->diagnostics()->writeWatchers)->toBe(0)
            ->and($loop->diagnostics()->timersActive)->toBe(0);
    } finally {
        $loop->cancel($pulse);
        $executor->close();
        unlink($link);
        unlink($nested);
        chmod($denied, 0600);
        unlink($denied);
        unlink($inside);
        unlink($outside);
        rmdir($root);
    }
});
