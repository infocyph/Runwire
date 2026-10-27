<?php

declare(strict_types=1);

use Infocyph\Runwire\Executor\ProcessExecutor;
use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Supervisor\WorkerContext;

it('drains every owned executor even when one completion observer throws', function (): void {
    $loop = new SelectLoop();
    $worker = new WorkerContext('executor-shutdown', 0, 1, posix_getpid(), posix_getpid());
    $first = new ProcessExecutor($loop, 1, 3);
    $second = new ProcessExecutor($loop, 1, 3);
    $settled = 0;

    try {
        $worker->ownExecutor($first);
        $worker->ownExecutor($second);
        $first->submit('sleep', ['milliseconds' => 200], static function (): void {
            throw new RuntimeException('Application callback failure during shutdown.');
        });
        $first->submit('sleep', ['milliseconds' => 200], static function () use (&$settled): void {
            ++$settled;
        });
        $second->submit('sleep', ['milliseconds' => 200], static function () use (&$settled): void {
            ++$settled;
        });

        $worker->requestStop();
        $worker->close();
        expect($settled)->toBe(2)
            ->and($first->diagnostics()['workers'])->toBe(0)
            ->and($second->diagnostics()['workers'])->toBe(0)
            ->and($first->diagnostics()['queued'])->toBe(0)
            ->and($second->diagnostics()['queued'])->toBe(0)
            ->and($loop->diagnostics()->readWatchers)->toBe(0)
            ->and($loop->diagnostics()->writeWatchers)->toBe(0)
            ->and($loop->diagnostics()->timersActive)->toBe(0);
    } finally {
        $first->close();
        $second->close();
        $worker->close();
    }
});
