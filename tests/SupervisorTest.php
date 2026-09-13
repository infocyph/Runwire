<?php

declare(strict_types=1);

use Infocyph\Runwire\Exception\SupervisorException;
use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Supervisor\RestartPolicy;
use Infocyph\Runwire\Supervisor\Supervisor;
use Infocyph\Runwire\Supervisor\WorkerContext;
use Infocyph\Runwire\Supervisor\WorkerGroup;

it('starts multiple workers and reaps them during graceful shutdown', function (): void {
    $loop = new SelectLoop();
    $supervisor = new Supervisor($loop);
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

    $loop->delay(0.03, static function () use ($supervisor): void {
        $supervisor->stop();
    });

    $supervisor->run();

    expect($supervisor->status()->workers)->toBe([]);

    $status = 0;
    expect(pcntl_waitpid(-1, $status, WNOHANG))->toBe(-1)
        ->and(pcntl_get_last_error())->toBe(PCNTL_ECHILD);
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
