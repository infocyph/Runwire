<?php

declare(strict_types=1);

require_once __DIR__ . '/Support/ProcessFixture.php';

use Infocyph\Runwire\Exception\SupervisorException;
use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Supervisor\RestartPolicy;
use Infocyph\Runwire\Supervisor\Supervisor;
use Infocyph\Runwire\Supervisor\WorkerContext;
use Infocyph\Runwire\Supervisor\WorkerGroup;

it('starts multiple workers and reaps them during graceful shutdown', function (): void {
    $loop = new SelectLoop();
    $supervisor = new Supervisor($loop);
    $workerPids = [];
    $supervisor->onEvent(static function (\Infocyph\Runwire\Supervisor\SupervisorEvent $event) use (&$workerPids): void {
        if ($event->type === \Infocyph\Runwire\Supervisor\Enum\SupervisorEventType::WORKER_READY && $event->pid !== null) {
            $workerPids[] = $event->pid;
        }
    });
    $supervisor->group(WorkerGroup::callbacks(
        name: 'workers',
        count: 2,
        factory: static function (WorkerContext $context): void {
            while (!$context->stopping()) {
                usleep(1_000);
            }
        },
        shutdownTimeoutSeconds: 0.1,
    ));

    $safety = $loop->delay(2.0, static function () use ($supervisor): void {
        $supervisor->stop(true);
    });
    $loop->repeat(0.005, static function (int $timer) use ($loop, $safety, $supervisor, &$workerPids): void {
        if (count($workerPids) < 2) {
            return;
        }

        $loop->cancel($timer);
        $loop->cancel($safety);
        $supervisor->stop();
    });

    $supervisor->run();

    expect($supervisor->status()->workers)->toBe([]);

    expect($workerPids)->toHaveCount(2);
    foreach ($workerPids as $pid) {
        $status = 0;
        // Only this supervisor's children; other QA cases may still own PIDs.
        expect(pcntl_waitpid($pid, $status, WNOHANG))->toBe(-1)
            ->and(pcntl_get_last_error())->toBe(PCNTL_ECHILD);
    }
});

it('restarts a crashed worker within the configured restart budget', function (): void {
    $counter = tempnam(sys_get_temp_dir(), 'runwire-restart-');
    if ($counter === false) {
        throw new RuntimeException('Unable to allocate restart counter.');
    }

    file_put_contents($counter, '0');

    try {
        $loop = new SelectLoop();
        $supervisor = new Supervisor($loop);
        $supervisor->group(WorkerGroup::callbacks(
            name: 'restart',
            count: 1,
            factory: static function (WorkerContext $context) use ($counter): void {
                $stream = fopen($counter, 'c+');
                if ($stream === false) {
                    throw new RuntimeException('Unable to open restart counter.');
                }

                flock($stream, LOCK_EX);
                $raw = stream_get_contents($stream);
                $attempt = ((int) $raw) + 1;
                ftruncate($stream, 0);
                rewind($stream);
                fwrite($stream, (string) $attempt);
                fflush($stream);
                flock($stream, LOCK_UN);
                fclose($stream);

                if ($attempt === 1) {
                    throw new RuntimeException('intentional crash');
                }

                while (!$context->stopping()) {
                    usleep(1_000);
                }
            },
            restartPolicy: new RestartPolicy(
                maxRestarts: 3,
                windowSeconds: 1.0,
                initialBackoffSeconds: 0.001,
                maxBackoffSeconds: 0.01,
            ),
            shutdownTimeoutSeconds: 0.1,
        ));

        $safety = $loop->delay(0.5, static function () use ($supervisor): void {
            $supervisor->stop(true);
        });
        $loop->repeat(0.005, static function (int $timer) use ($counter, $loop, $safety, $supervisor): void {
            if ((int) file_get_contents($counter) < 2) {
                return;
            }

            $loop->cancel($timer);
            $loop->cancel($safety);
            $supervisor->stop();
        });

        $supervisor->run();

        expect((int) file_get_contents($counter))->toBeGreaterThanOrEqual(2);
    } finally {
        if (file_exists($counter)) {
            unlink($counter);
        }
    }
});

it('fails the supervisor when a worker exhausts its restart budget', function (): void {
    $loop = new SelectLoop();
    $supervisor = new Supervisor($loop);
    $supervisor->group(WorkerGroup::callbacks(
        name: 'failing',
        count: 1,
        factory: static function (): void {
            throw new RuntimeException('always fails');
        },
        restartPolicy: new RestartPolicy(
            maxRestarts: 1,
            windowSeconds: 1.0,
            initialBackoffSeconds: 0.0,
            maxBackoffSeconds: 0.0,
        ),
        shutdownTimeoutSeconds: 0.1,
    ));

    $supervisor->run();
})->throws(SupervisorException::class, 'Restart budget exhausted');

it('performs a rolling generation reload after replacement readiness', function (): void {
    $generations = tempnam(sys_get_temp_dir(), 'runwire-reload-');
    if ($generations === false) {
        throw new RuntimeException('Unable to allocate generation log.');
    }

    file_put_contents($generations, '');

    try {
        $loop = new SelectLoop();
        $supervisor = new Supervisor($loop);
        $supervisor->group(WorkerGroup::callbacks(
            name: 'reload',
            count: 1,
            factory: static function (WorkerContext $context) use ($generations): void {
                file_put_contents(
                    $generations,
                    $context->generation . PHP_EOL,
                    FILE_APPEND | LOCK_EX,
                );
                $context->ready();

                while (!$context->stopping()) {
                    usleep(1_000);
                }
            },
            automaticReady: false,
            readyTimeoutSeconds: 0.1,
            shutdownTimeoutSeconds: 0.1,
        ));

        $loop->delay(0.02, static function () use ($supervisor): void {
            $supervisor->reload();
        });
        $loop->delay(0.08, static function () use ($supervisor): void {
            $supervisor->stop();
        });

        $supervisor->run();

        $observed = file($generations, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        expect($observed)->toContain('1')->toContain('2');
    } finally {
        if (file_exists($generations)) {
            unlink($generations);
        }
    }
});

it('preserves an unrelated child exit status while reaping supervisor-owned workers', function (): void {
    $loop = new SelectLoop();
    $supervisor = new Supervisor($loop);
    $supervisor->group(WorkerGroup::callbacks(
        name: 'owned',
        count: 1,
        factory: static function (WorkerContext $context): void {
            while (!$context->stopping()) {
                usleep(1_000);
            }
        },
        shutdownTimeoutSeconds: 0.2,
    ));
    $foreign = pcntl_fork();
    if ($foreign < 0) {
        throw new RuntimeException('Unable to fork unrelated child fixture.');
    }
    if ($foreign === 0) {
        pcntl_exec(PHP_BINARY, ['-r', 'exit(37);']);
        throw new RuntimeException('Unable to replace unrelated child fixture.');
    }
    $stop = $loop->delay(0.25, static function () use ($supervisor): void {
        $supervisor->stop();
    });

    try {
        $supervisor->run();
        $waited = pcntl_waitpid($foreign, $status);
        expect($waited)->toBe($foreign);
        $exitStatus = \Infocyph\Runwire\Tests\Support\waitStatus($status);
        expect(pcntl_wifexited($exitStatus))->toBeTrue()
            ->and(pcntl_wexitstatus($exitStatus))->toBe(37)
            ->and($supervisor->status()->workerCount)->toBe(0)
            ->and($loop->diagnostics()->readWatchers)->toBe(0)
            ->and($loop->diagnostics()->timersActive)->toBe(0);
    } finally {
        $loop->cancel($stop);
        if (pcntl_waitpid($foreign, $cleanupStatus, WNOHANG) === 0) {
            posix_kill($foreign, SIGKILL);
            pcntl_waitpid($foreign, $cleanupStatus);
        }
    }
});

it('fails closed and drains remaining workers if one registered child was externally reaped', function (): void {
    $loop = new SelectLoop();
    $supervisor = new Supervisor($loop);
    $pids = [];
    $externallyReaped = false;
    $supervisor->onEvent(static function (\Infocyph\Runwire\Supervisor\SupervisorEvent $event) use (&$pids, &$externallyReaped): void {
        if ($event->type !== \Infocyph\Runwire\Supervisor\Enum\SupervisorEventType::WORKER_READY || $event->pid === null) {
            return;
        }
        $pids[] = $event->pid;
        if (count($pids) === 2) {
            posix_kill($pids[0], SIGKILL);
            $externallyReaped = pcntl_waitpid($pids[0], $status) === $pids[0];
        }
    });
    $supervisor->group(WorkerGroup::callbacks(
        name: 'owned',
        count: 2,
        factory: static function (WorkerContext $context): void {
            while (!$context->stopping()) {
                usleep(1_000);
            }
        },
        shutdownTimeoutSeconds: 0.2,
    ));
    $watchdog = $loop->delay(2.0, static function () use ($supervisor): void {
        $supervisor->stop(true);
    });

    try {
        expect(fn() => $supervisor->run())->toThrow(SupervisorException::class, 'Kernel child state diverged');
        expect($externallyReaped)->toBeTrue()
            ->and($pids)->toHaveCount(2)
            ->and($supervisor->status()->workerCount)->toBe(0);
        foreach ($pids as $pid) {
            expect(pcntl_waitpid($pid, $status, WNOHANG))->toBe(-1)
                ->and(pcntl_get_last_error())->toBe(PCNTL_ECHILD);
        }
    } finally {
        $loop->cancel($watchdog);
    }
    expect($loop->diagnostics()->readWatchers)->toBe(0)
        ->and($loop->diagnostics()->timersActive)->toBe(0);
});
