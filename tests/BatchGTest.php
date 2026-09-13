<?php

declare(strict_types=1);

use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Runtime\DevelopmentWatchPolicy;
use Infocyph\Runwire\Runtime\Internal\DevelopmentFileScanner;
use Infocyph\Runwire\Runtime\Internal\DevelopmentWatcher;
use Infocyph\Runwire\Supervisor\Enum\WorkerRole;
use Infocyph\Runwire\Supervisor\Supervisor;
use Infocyph\Runwire\Supervisor\WorkerContext;
use Infocyph\Runwire\Supervisor\WorkerGroup;

it('ticks due work without starting the blocking event loop', function (): void {
    $loop = new SelectLoop();
    $runs = 0;
    $loop->delay(0.0, static function () use (&$runs): void {
        ++$runs;
    });

    $loop->tick();

    expect($runs)->toBe(1);
});

it('runs named periodic work and stops scheduling it when drain begins', function (): void {
    [$readyParent, $readyChild] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    $context = new WorkerContext(
        'tasks',
        0,
        1,
        posix_getpid(),
        posix_getppid(),
        $readyChild,
        role: WorkerRole::TASK,
    );
    $loop = new SelectLoop();
    $context->attachLoop($loop);
    $runs = 0;
    $handle = $context->every('heartbeat', 0.001, static function () use (&$runs, $context): void {
        ++$runs;
        if ($runs === 2) {
            $context->requestStop();
        }
    });
    $loop->onReadable($context->stopStream(), static function () use ($context, $loop): void {
        $context->consumeStopWake();
        $loop->stop();
    });

    $loop->run();

    expect($runs)->toBe(2)
        ->and($context->acceptingBackgroundWork())->toBeFalse()
        ->and($handle->cancel())->toBeTrue()
        ->and($handle->cancel())->toBeFalse()
        ->and(fn () => $context->every('late', 1.0, static function (): void {}))
        ->toThrow(LogicException::class, 'draining');

    $context->close();
    fclose($readyParent);
});

it('rejects duplicate periodic task names per worker', function (): void {
    [$readyParent, $readyChild] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    $context = new WorkerContext('tasks', 0, 1, posix_getpid(), posix_getppid(), $readyChild);
    $context->attachLoop(new SelectLoop());
    $context->every('same-name', 1.0, static function (): void {});

    try {
        expect(fn () => $context->every('same-name', 1.0, static function (): void {}))
            ->toThrow(LogicException::class, 'already registered');
    } finally {
        $context->close();
        fclose($readyParent);
    }
});

it('applies role-aware worker group lifecycle defaults', function (): void {
    $factory = static function (WorkerContext $context): void {
        $context->requestStop();
    };
    $service = WorkerGroup::callbacks('service', 1, $factory, role: WorkerRole::SERVICE);
    $task = WorkerGroup::callbacks('task', 1, $factory, role: WorkerRole::TASK);
    $reloadableService = WorkerGroup::callbacks(
        'service-reloadable',
        1,
        $factory,
        reloadable: true,
        role: WorkerRole::SERVICE,
    );

    expect($service->reloadable)->toBeFalse()
        ->and($service->role->background())->toBeTrue()
        ->and($task->reloadable)->toBeTrue()
        ->and($reloadableService->reloadable)->toBeTrue();
});

it('drives periodic work automatically for task worker groups and reports their role', function (): void {
    $counter = tempnam(sys_get_temp_dir(), 'runwire-task-');
    if ($counter === false) {
        throw new RuntimeException('Unable to allocate periodic task counter.');
    }
    file_put_contents($counter, '');

    try {
        $loop = new SelectLoop();
        $supervisor = new Supervisor($loop);
        $observedRole = null;
        $supervisor->group(WorkerGroup::callbacks(
            name: 'tasks',
            count: 1,
            factory: static function (WorkerContext $context) use ($counter): void {
                $context->every('counter', 0.005, static function () use ($counter): void {
                    file_put_contents($counter, "1\n", FILE_APPEND | LOCK_EX);
                });
            },
            shutdownTimeoutSeconds: 0.1,
            role: WorkerRole::TASK,
        ));
        $loop->delay(0.04, static function () use ($supervisor, &$observedRole): void {
            $observedRole = $supervisor->status()->workers[0]->role ?? null;
        });
        $loop->delay(0.12, static function () use ($supervisor): void {
            $supervisor->stop();
        });

        $supervisor->run();
        $before = (string) file_get_contents($counter);
        usleep(20_000);
        $after = (string) file_get_contents($counter);

        expect(substr_count($before, "1\n"))->toBeGreaterThan(1)
            ->and($after)->toBe($before)
            ->and($observedRole)->toBe(WorkerRole::TASK);
    } finally {
        if (file_exists($counter)) {
            unlink($counter);
        }
    }
});

it('debounces development changes and isolates watcher failures', function (): void {
    $loop = new SelectLoop();
    $policy = new DevelopmentWatchPolicy(
        enabled: true,
        paths: ['virtual-root'],
        pollIntervalSeconds: 0.05,
        debounceSeconds: 0.01,
    );
    $snapshots = 0;
    $reloads = 0;
    $watcher = new DevelopmentWatcher(
        $loop,
        $policy,
        static function () use (&$reloads): void {
            ++$reloads;
            throw new RuntimeException('reload callback failure');
        },
        static function () use (&$snapshots): string {
            ++$snapshots;
            if ($snapshots === 1) {
                throw new RuntimeException('snapshot failure');
            }

            return $snapshots === 2 ? 'a' : 'b';
        },
    );
    $watcher->start();
    $loop->delay(0.18, static function () use ($loop, $watcher): void {
        $watcher->stop();
        $loop->stop();
    });

    $loop->run();

    expect($reloads)->toBe(1)
        ->and($watcher->failureCount())->toBe(2)
        ->and($watcher->running())->toBeFalse();
});

it('bounds development watcher file scans', function (): void {
    $directory = sys_get_temp_dir() . '/runwire-watch-' . bin2hex(random_bytes(6));
    if (!mkdir($directory) && !is_dir($directory)) {
        throw new RuntimeException('Unable to create development watcher fixture directory.');
    }
    file_put_contents($directory . '/a.php', '<?php');
    file_put_contents($directory . '/b.php', '<?php echo 1;');

    try {
        $policy = new DevelopmentWatchPolicy(enabled: true, paths: [$directory], maxFiles: 1);
        expect(fn () => (new DevelopmentFileScanner($policy))->snapshot())
            ->toThrow(RuntimeException::class, 'file limit');
    } finally {
        unlink($directory . '/a.php');
        unlink($directory . '/b.php');
        rmdir($directory);
    }
});

it('routes development file changes through the existing rolling reload path', function (): void {
    $directory = sys_get_temp_dir() . '/runwire-watch-' . bin2hex(random_bytes(6));
    if (!mkdir($directory) && !is_dir($directory)) {
        throw new RuntimeException('Unable to create watcher integration directory.');
    }
    $watched = $directory . '/watched.php';
    $generations = $directory . '/generations.log';
    file_put_contents($watched, 'a');
    file_put_contents($generations, '');

    try {
        $loop = new SelectLoop();
        $supervisor = new Supervisor($loop);
        $supervisor->watch(new DevelopmentWatchPolicy(
            enabled: true,
            paths: [$watched],
            pollIntervalSeconds: 0.05,
            debounceSeconds: 0.01,
        ));
        $supervisor->group(WorkerGroup::callbacks(
            name: 'reloadable',
            count: 1,
            factory: static function (WorkerContext $context) use ($generations): void {
                file_put_contents(
                    $generations,
                    $context->generation . PHP_EOL,
                    FILE_APPEND | LOCK_EX,
                );
                while (!$context->stopping()) {
                    usleep(1_000);
                }
            },
            shutdownTimeoutSeconds: 0.1,
        ));
        $loop->delay(0.08, static function () use ($watched): void {
            file_put_contents($watched, 'changed-content');
        });
        $loop->delay(0.25, static function () use ($supervisor): void {
            $supervisor->stop();
        });

        $supervisor->run();

        $observed = file($generations, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        expect($observed)->toContain('1')->toContain('2')
            ->and($supervisor->status()->developmentWatcherFailures)->toBe(0)
            ->and($supervisor->status()->developmentWatcherActive)->toBeFalse();
    } finally {
        if (file_exists($watched)) {
            unlink($watched);
        }
        if (file_exists($generations)) {
            unlink($generations);
        }
        rmdir($directory);
    }
});
