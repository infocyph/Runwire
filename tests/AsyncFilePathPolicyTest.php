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
    expect($outside)->toBeString();
    file_put_contents($inside, 'protected');
    file_put_contents($outside, 'unchanged');
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

        expect($results)->toHaveCount(7)
            ->and($results['allowed'])->toBe(['protected', null])
            ->and($results['symlink'][1])->toBeInstanceOf(Throwable::class)
            ->and($results['outside'][1])->toBeInstanceOf(Throwable::class)
            ->and($results['traversal'][1])->toBeInstanceOf(Throwable::class)
            ->and($results['rename'][1])->toBeInstanceOf(Throwable::class)
            ->and($results['unlink'][1])->toBeInstanceOf(Throwable::class)
            ->and($results['nested'][1])->toBeInstanceOf(Throwable::class)
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
        unlink($inside);
        unlink($outside);
        rmdir($root);
    }
});
