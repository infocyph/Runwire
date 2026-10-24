<?php

declare(strict_types=1);

use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Supervisor\Enum\WorkerRole;
use Infocyph\Runwire\Supervisor\Supervisor;
use Infocyph\Runwire\Supervisor\WorkerContext;
use Infocyph\Runwire\Supervisor\WorkerGroup;

it('bounds an in-flight periodic callback by the worker shutdown deadline', function (): void {
    $started = tempnam(sys_get_temp_dir(), 'runwire-task-started-');
    if ($started === false) {
        throw new RuntimeException('Unable to allocate periodic callback marker.');
    }
    file_put_contents($started, '');

    try {
        $loop = new SelectLoop();
        $supervisor = new Supervisor($loop);
        $supervisor->group(WorkerGroup::callbacks(
            name: 'blocking-task',
            count: 1,
            factory: static function (WorkerContext $context) use ($started): void {
                $context->every('blocking', 0.001, static function () use ($started): void {
                    file_put_contents($started, 'started', LOCK_EX);
                    $deadline = hrtime(true) + 500_000_000;
                    while (hrtime(true) < $deadline) {
                        usleep(10_000);
                    }
                });
            },
            shutdownTimeoutSeconds: 0.05,
            role: WorkerRole::TASK,
        ));
        $poll = $loop->repeat(0.005, static function (int $timer) use ($loop, $started, $supervisor): void {
            if (filesize($started) === 0) {
                clearstatcache(true, $started);

                return;
            }

            $loop->cancel($timer);
            $supervisor->stop();
        });
        $loop->delay(0.5, static function () use ($loop, $poll, $supervisor): void {
            $loop->cancel($poll);
            $supervisor->stop(true);
        });

        $startedAt = microtime(true);
        $supervisor->run();
        $elapsed = microtime(true) - $startedAt;

        expect((string) file_get_contents($started))->toBe('started')
            ->and($elapsed)->toBeLessThan(0.35)
            ->and($supervisor->status()->workers)->toBe([]);
    } finally {
        if (file_exists($started)) {
            unlink($started);
        }
    }
});
