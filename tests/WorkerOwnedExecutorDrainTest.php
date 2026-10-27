<?php

declare(strict_types=1);

use Infocyph\Runwire\Exception\CancelledException;
use Infocyph\Runwire\Executor\ProcessExecutor;
use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Supervisor\WorkerContext;

it('cancels owned queued file jobs once when the worker stops and rejects late ownership', function (): void {
    $loop = new SelectLoop();
    $worker = new WorkerContext('executor-owner', 0, 1, getmypid(), getmypid());
    $executor = new ProcessExecutor($loop, 1, 4);
    $callbacks = [];

    try {
        $worker->ownExecutor($executor);
        $executor->submit('sleep', ['milliseconds' => 100], static function (mixed $value, ?Throwable $error) use (&$callbacks): void {
            $callbacks[] = $error;
        });
        $executor->submit('sleep', ['milliseconds' => 100], static function (mixed $value, ?Throwable $error) use (&$callbacks): void {
            $callbacks[] = $error;
        });
        $worker->requestStop();
        $worker->requestStop();
        $worker->close();

        expect($callbacks)->toHaveCount(2);
        foreach ($callbacks as $error) {
            expect($error)->toBeInstanceOf(CancelledException::class);
        }

        expect($executor->diagnostics()['workers'])->toBe(0)
            ->and($executor->diagnostics()['queued'])->toBe(0)
            ->and($executor->diagnostics()['active'])->toBe(0)
            ->and($loop->diagnostics()->readWatchers)->toBe(0)
            ->and($loop->diagnostics()->writeWatchers)->toBe(0)
            ->and($loop->diagnostics()->timersActive)->toBe(0);

        expect(static fn() => $worker->ownExecutor($executor))->toThrow(LogicException::class);
    } finally {
        $executor->close();
        $worker->close();
    }
});
