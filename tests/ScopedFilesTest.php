<?php

declare(strict_types=1);

use Infocyph\Runwire\Coroutine\CoroutineRuntime;
use Infocyph\Runwire\Coroutine\CoroutineScope;
use Infocyph\Runwire\Coroutine\Filesystem\ScopedFiles;
use Infocyph\Runwire\Executor\ProcessExecutor;
use Infocyph\Runwire\Filesystem\AsyncFileSystem;
use Infocyph\Runwire\Loop\SelectLoop;

it('awaits the shared async file executor within an owned Fiber and cleans every worker', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'runwire-scoped-file-');
    expect($path)->toBeString();
    $target = $path . '.next';
    $loop = new SelectLoop();
    $executor = new ProcessExecutor($loop, 1, 4);
    $files = new AsyncFileSystem($executor);
    $runtime = new CoroutineRuntime($loop);

    try {
        $result = $runtime->run(static function (CoroutineScope $scope) use ($executor, $files, $path, $target): string {
            $scope->registerShutdown(static function () use ($executor): void {
                $executor->close();
            });
            $scoped = new ScopedFiles($files, $scope);
            expect($scoped->write($path, "abc\0xyz"))->toBe(7);
            $content = $scoped->read($path, 7);
            $metadata = $scoped->stat($path);
            expect($metadata['size'])->toBe(7)
                ->and($scoped->rename($path, $target))->toBeTrue()
                ->and($scoped->unlink($target))->toBeTrue();

            return $content;
        });

        expect($result)->toBe("abc\0xyz")
            ->and($executor->diagnostics()['workers'])->toBe(0)
            ->and($loop->diagnostics()->readWatchers)->toBe(0)
            ->and($loop->diagnostics()->writeWatchers)->toBe(0);
    } finally {
        $executor->close();
        if (is_file($path)) {
            unlink($path);
        }
        if (is_file($target)) {
            unlink($target);
        }
    }
});
