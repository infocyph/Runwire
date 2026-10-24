<?php

declare(strict_types=1);

use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Supervisor\Enum\SupervisorEventType;
use Infocyph\Runwire\Supervisor\RestartPolicy;
use Infocyph\Runwire\Supervisor\Supervisor;
use Infocyph\Runwire\Supervisor\WorkerContext;
use Infocyph\Runwire\Supervisor\WorkerGroup;
use Infocyph\Runwire\Supervisor\WorkerRecyclePolicy;

it('replaces a self-recycling native worker without consuming the crash restart budget', function (): void {
    $counter = tempnam(sys_get_temp_dir(), 'runwire-recycle-');
    if ($counter === false) {
        throw new RuntimeException('Unable to allocate recycle counter.');
    }
    file_put_contents($counter, '0');

    try {
        $loop = new SelectLoop();
        $supervisor = new Supervisor($loop);
        $events = [];
        $supervisor->onEvent(static function ($event) use (&$events): void {
            $events[] = $event->type;
        });
        $supervisor->group(WorkerGroup::callbacks(
            name: 'self-recycle',
            count: 1,
            factory: static function (WorkerContext $context) use ($counter): void {
                $stream = fopen($counter, 'c+');
                if ($stream === false) {
                    throw new RuntimeException('Unable to open recycle counter.');
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
                    $context->recordRequestCompleted();

                    return;
                }

                while (!$context->stopping()) {
                    usleep(1_000);
                }
            },
            restartPolicy: new RestartPolicy(maxRestarts: 0),
            recyclePolicy: new WorkerRecyclePolicy(maxRequests: 1),
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

        expect((int) file_get_contents($counter))->toBeGreaterThanOrEqual(2)
            ->and($events)->toContain(SupervisorEventType::WORKER_RECYCLE_STARTED)
            ->and($events)->not->toContain(SupervisorEventType::WORKER_RESTART_SCHEDULED);
    } finally {
        if (file_exists($counter)) {
            unlink($counter);
        }
    }
});
