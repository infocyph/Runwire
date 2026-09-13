<?php

declare(strict_types=1);

use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Supervisor\Enum\ShutdownReason;
use Infocyph\Runwire\Supervisor\Enum\SupervisorEventType;
use Infocyph\Runwire\Supervisor\ReloadPolicy;
use Infocyph\Runwire\Supervisor\RestartPolicy;
use Infocyph\Runwire\Supervisor\Supervisor;
use Infocyph\Runwire\Supervisor\SupervisorEvent;
use Infocyph\Runwire\Supervisor\SupervisorStatus;
use Infocyph\Runwire\Supervisor\WorkerContext;
use Infocyph\Runwire\Supervisor\WorkerGroup;

it('validates bounded rolling reload policy', function (): void {
    expect(static fn() => new ReloadPolicy(maxSurge: 0))->toThrow(InvalidArgumentException::class)
        ->and(static fn() => new ReloadPolicy(maxUnavailable: -1))->toThrow(InvalidArgumentException::class)
        ->and(static fn() => new ReloadPolicy(replacementReadyTimeoutSeconds: 0.0))->toThrow(InvalidArgumentException::class)
        ->and(static fn() => new ReloadPolicy(drainTimeoutSeconds: INF))->toThrow(InvalidArgumentException::class);
});

it('reports worker activity and receives a shutdown reason over lifecycle IPC', function (): void {
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    if (!is_array($pair) || count($pair) !== 2) {
        throw new RuntimeException('Unable to create lifecycle test channel.');
    }

    [$master, $worker] = $pair;
    stream_set_blocking($master, false);
    $context = new WorkerContext(
        group: 'lifecycle',
        slot: 0,
        generation: 1,
        pid: getmypid(),
        parentPid: posix_getppid(),
        readyStream: $worker,
    );

    try {
        $context->ready();
        $context->recordRequestStarted();
        $context->recordRequestCompleted();
        $context->markUnhealthy();
        fwrite($master, 'S:' . ShutdownReason::DEPLOYMENT_RELOAD->value . "\n");
        $context->requestStop();

        $messages = '';
        for ($attempt = 0; $attempt < 20; ++$attempt) {
            $chunk = fread($master, 8_192);
            if (is_string($chunk) && $chunk !== '') {
                $messages .= $chunk;
            }
            if (str_contains($messages, "U\n")) {
                break;
            }
            usleep(1_000);
        }

        expect($messages)->toContain("R\n")
            ->toContain("B\n")
            ->toContain("I\n")
            ->toContain("U\n")
            ->and($context->shutdownReason())->toBe(ShutdownReason::DEPLOYMENT_RELOAD);
    } finally {
        $context->close();
        if (is_resource($master)) {
            fclose($master);
        }
    }
});

it('caps reload surge and marks the completed generation ready', function (): void {
    $loop = new SelectLoop();
    $supervisor = new Supervisor($loop, new ReloadPolicy(
        maxUnavailable: 0,
        maxSurge: 1,
        replacementReadyTimeoutSeconds: 0.2,
        drainTimeoutSeconds: 0.1,
    ));
    $generationTwoSpawns = 0;
    $spawnsBeforeFirstReady = 0;
    $firstReady = false;
    $snapshot = null;

    $supervisor->onEvent(static function (SupervisorEvent $event) use (
        $supervisor,
        &$generationTwoSpawns,
        &$spawnsBeforeFirstReady,
        &$firstReady,
        &$snapshot,
    ): void {
        if ($event->generation !== 2) {
            return;
        }
        if ($event->type === SupervisorEventType::WORKER_SPAWNED) {
            ++$generationTwoSpawns;
            $spawnsBeforeFirstReady += $firstReady ? 0 : 1;
        }
        if ($event->type === SupervisorEventType::WORKER_READY) {
            $firstReady = true;
        }
        if ($event->type === SupervisorEventType::RELOAD_COMPLETED) {
            $snapshot = $supervisor->status();
            $supervisor->stop();
        }
    });
    $supervisor->group(WorkerGroup::callbacks(
        name: 'rolling',
        count: 3,
        factory: static function (WorkerContext $context): void {
            if ($context->generation === 2) {
                usleep(40_000);
            }
            $context->ready();
            while (!$context->stopping()) {
                usleep(1_000);
            }
        },
        automaticReady: false,
        readyTimeoutSeconds: 0.2,
        shutdownTimeoutSeconds: 0.1,
    ));

    $loop->delay(0.02, static fn() => $supervisor->reload());
    $loop->delay(1.0, static fn() => $supervisor->stop());
    $supervisor->run();

    expect($spawnsBeforeFirstReady)->toBe(1)
        ->and($generationTwoSpawns)->toBe(3)
        ->and($snapshot)->toBeInstanceOf(SupervisorStatus::class)
        ->and($snapshot?->generation)->toBe(2)
        ->and($snapshot?->generationReady)->toBeTrue()
        ->and($snapshot?->reloading)->toBeFalse();
});

it('does not reload groups marked non reloadable', function (): void {
    $appLog = tempnam(sys_get_temp_dir(), 'runwire-app-');
    $stableLog = tempnam(sys_get_temp_dir(), 'runwire-stable-');
    if ($appLog === false || $stableLog === false) {
        throw new RuntimeException('Unable to allocate reload generation logs.');
    }
    file_put_contents($appLog, '');
    file_put_contents($stableLog, '');

    try {
        $loop = new SelectLoop();
        $supervisor = new Supervisor($loop, new ReloadPolicy(maxSurge: 1));
        $factory = static function (string $file): Closure {
            return static function (WorkerContext $context) use ($file): void {
                file_put_contents($file, $context->generation . PHP_EOL, FILE_APPEND | LOCK_EX);
                $context->ready();
                while (!$context->stopping()) {
                    usleep(1_000);
                }
            };
        };
        $supervisor->group(WorkerGroup::callbacks('app', 1, $factory($appLog), automaticReady: false));
        $supervisor->group(WorkerGroup::callbacks(
            'control',
            1,
            $factory($stableLog),
            automaticReady: false,
            reloadable: false,
        ));

        $loop->delay(0.03, static fn() => $supervisor->reload());
        $loop->delay(0.12, static fn() => $supervisor->stop());
        $supervisor->run();

        expect(file($appLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES))->toBe(['1', '2'])
            ->and(file($stableLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES))->toBe(['1']);
    } finally {
        if (file_exists($appLog)) {
            unlink($appLog);
        }
        if (file_exists($stableLog)) {
            unlink($stableLog);
        }
    }
});

it('aborts a failed rollout while preserving healthy old capacity', function (): void {
    $loop = new SelectLoop();
    $supervisor = new Supervisor($loop, new ReloadPolicy(
        maxUnavailable: 0,
        maxSurge: 1,
        replacementReadyTimeoutSeconds: 0.02,
        drainTimeoutSeconds: 0.05,
    ));
    $snapshot = null;
    $reloadFailedEvents = 0;
    $supervisor->onEvent(static function (SupervisorEvent $event) use (&$reloadFailedEvents): void {
        $reloadFailedEvents += $event->type === SupervisorEventType::RELOAD_FAILED ? 1 : 0;
    });
    $supervisor->group(WorkerGroup::callbacks(
        name: 'rollback',
        count: 1,
        factory: static function (WorkerContext $context): void {
            if ($context->generation === 1) {
                $context->ready();
            }
            while (!$context->stopping()) {
                usleep(1_000);
            }
        },
        restartPolicy: new RestartPolicy(1, 1.0, 0.001, 0.001),
        automaticReady: false,
        readyTimeoutSeconds: 0.1,
        shutdownTimeoutSeconds: 0.05,
    ));

    $loop->delay(0.02, static fn() => $supervisor->reload());
    $loop->delay(0.11, static function () use ($supervisor, &$snapshot): void {
        $snapshot = $supervisor->status();
        $supervisor->stop();
    });
    $supervisor->run();

    expect($snapshot)->toBeInstanceOf(SupervisorStatus::class)
        ->and($snapshot?->reloadFailed)->toBeTrue()
        ->and($snapshot?->reloading)->toBeFalse()
        ->and($snapshot?->currentWorkerCount)->toBe(1)
        ->and($snapshot?->workers)->toHaveCount(1)
        ->and($snapshot?->workers[0]->generation)->toBe(1)
        ->and($snapshot?->workers[0]->state->serving())->toBeTrue()
        ->and($snapshot?->restartReasonCounts['restart_budget_exhausted'])->toBe(1)
        ->and($reloadFailedEvents)->toBe(1);
});
