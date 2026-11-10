<?php

declare(strict_types=1);

use Infocyph\Runwire\Executor\ProcessExecutor;
use Infocyph\Runwire\Filesystem\AsyncFileSystem;
use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\RequestDeadline;

it('refuses FIFO data operations in the actual off-loop executor before opening them', function (): void {
    $directory = sys_get_temp_dir() . '/runwire-fifo-safety-' . bin2hex(random_bytes(6));
    expect(mkdir($directory, 0700))->toBeTrue();
    $fifo = $directory . '/blocked';
    expect(posix_mkfifo($fifo, 0600))->toBeTrue();

    $loop = new SelectLoop();
    $executor = new ProcessExecutor($loop, 1, 2);
    $filesystem = new AsyncFileSystem($executor, $directory);
    $errors = [];

    try {
        $deadline = RequestDeadline::afterSeconds(1.5, hrtime(true));
        $filesystem->read(
            $fifo,
            16,
            static function (string $bytes, ?Throwable $error) use (&$errors): void {
                expect($bytes)->toBe('');
                $errors['read'] = $error;
            },
            deadline: $deadline,
        );
        $filesystem->write(
            $fifo,
            'data',
            static function (int $written, ?Throwable $error) use (&$errors): void {
                expect($written)->toBe(0);
                $errors['write'] = $error;
            },
            deadline: $deadline,
        );
        $executor->onDrained(static function () use ($executor): void {
            $executor->close();
        });
        $loop->run();

        expect($errors)->toHaveCount(2);
        foreach (['read', 'write'] as $operation) {
            $error = $errors[$operation] ?? null;
            if (!$error instanceof RuntimeException) {
                throw new RuntimeException('Executor did not reject the non-regular file.');
            }
            expect($error->getMessage())->toContain('regular file');
        }
        expect($executor->diagnostics()['workers'])->toBe(0);
    } finally {
        $executor->close();
        unlink($fifo);
        rmdir($directory);
    }
});
