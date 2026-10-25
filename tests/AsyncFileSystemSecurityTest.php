<?php

declare(strict_types=1);

use Infocyph\Runwire\Executor\ProcessExecutor;
use Infocyph\Runwire\Filesystem\AsyncFileSystem;
use Infocyph\Runwire\Loop\SelectLoop;

it('rejects restricted-root traversal, symlinks and denied permissions in the actual off-loop worker', function (): void {
    $base = sys_get_temp_dir() . '/runwire-fs-security-' . bin2hex(random_bytes(6));
    $root = $base . '/allowed';
    $outside = $base . '/outside';
    expect(mkdir($base, 0700))->toBeTrue()
        ->and(mkdir($root, 0700))->toBeTrue()
        ->and(mkdir($outside, 0700))->toBeTrue();

    $allowed = $root . '/safe.txt';
    $protected = $root . '/protected.txt';
    $secret = $outside . '/secret.txt';
    $symlink = $root . '/external-file';
    $symlinkDirectory = $root . '/external-directory';
    $rootAlias = $base . '/root-alias';
    file_put_contents($allowed, 'allowed-data');
    file_put_contents($protected, 'locked-data');
    file_put_contents($secret, 'confidential');
    expect(chmod($protected, 0000))->toBeTrue()
        ->and(symlink($secret, $symlink))->toBeTrue()
        ->and(symlink($outside, $symlinkDirectory))->toBeTrue()
        ->and(symlink($root, $rootAlias))->toBeTrue();

    $loop = new SelectLoop();
    $executor = new ProcessExecutor($loop, 1, 20);
    $fs = new AsyncFileSystem($executor, $root);
    $aliasedFs = new AsyncFileSystem($executor, $rootAlias);
    $results = [];
    $read = static function (string $case, AsyncFileSystem $filesystem, string $path) use (&$results): void {
        $filesystem->read(
            $path,
            20,
            static function (string $bytes, ?Throwable $error) use (&$results, $case): void {
                $results[$case] = [$bytes, $error];
            },
        );
    };
    $write = static function (string $case, string $path) use ($fs, &$results): void {
        $fs->write(
            $path,
            'modified',
            static function (int $bytes, ?Throwable $error) use (&$results, $case): void {
                $results[$case] = [$bytes, $error];
            },
        );
    };

    try {
        $read('valid', $fs, $allowed);
        $read('traversal', $fs, $root . '/../outside/secret.txt');
        $read('prefix', $fs, $outside . '/secret.txt');
        $read('leaf-link', $fs, $symlink);
        $read('parent-link', $fs, $symlinkDirectory . '/secret.txt');
        $read('denied-read', $fs, $protected);
        $read('root-alias', $aliasedFs, $rootAlias . '/safe.txt');
        $write('denied-write', $protected);
        $write('linked-write', $symlink);
        $fs->stat(
            $symlink,
            static function (array $metadata, ?Throwable $error) use (&$results): void {
                $results['linked-stat'] = [$metadata, $error];
            },
        );
        $fs->unlink(
            $symlink,
            static function (bool $removed, ?Throwable $error) use (&$results): void {
                $results['linked-unlink'] = [$removed, $error];
            },
        );
        $fs->rename(
            $allowed,
            $symlink,
            static function (bool $renamed, ?Throwable $error) use (&$results): void {
                $results['linked-rename'] = [$renamed, $error];
            },
        );
        $executor->onDrained(static function () use ($executor): void {
            $executor->close();
        });
        $loop->run();

        expect($results)->toHaveCount(12)
            ->and($results['valid'])->toBe(['allowed-data', null]);
        foreach ([
            'traversal', 'prefix', 'leaf-link', 'parent-link', 'denied-read',
            'root-alias', 'denied-write', 'linked-write', 'linked-stat',
            'linked-unlink', 'linked-rename',
        ] as $case) {
            expect($results[$case][1])->toBeInstanceOf(RuntimeException::class, $case);
        }
        expect(file_get_contents($secret))->toBe('confidential')
            ->and(file_get_contents($allowed))->toBe('allowed-data')
            ->and(is_link($symlink))->toBeTrue()
            ->and($executor->diagnostics()['workers'])->toBe(0)
            ->and($loop->diagnostics()->readWatchers)->toBe(0)
            ->and($loop->diagnostics()->writeWatchers)->toBe(0)
            ->and($loop->diagnostics()->timersActive)->toBe(0);
    } finally {
        $executor->close();
        chmod($protected, 0600);
        foreach ([$symlink, $symlinkDirectory, $rootAlias, $allowed, $protected, $secret] as $file) {
            if (is_file($file) || is_link($file)) {
                unlink($file);
            }
        }
        rmdir($root);
        rmdir($outside);
        rmdir($base);
    }
});
