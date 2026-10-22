<?php

declare(strict_types=1);

use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Supervisor\Enum\SupervisorEventType;
use Infocyph\Runwire\Supervisor\RestartPolicy;
use Infocyph\Runwire\Supervisor\Supervisor;
use Infocyph\Runwire\Supervisor\SupervisorEvent;
use Infocyph\Runwire\Supervisor\WorkerContext;
use Infocyph\Runwire\Supervisor\WorkerGroup;

it('recycles one worker after replacement readiness and restores normal restart ownership', function (): void {
    $loop = new SelectLoop();
    $supervisor = new Supervisor($loop);
    $events = [];
    $readyPids = [];
    $recycleResults = [];
    $statusDuring = null;

    $supervisor->onEvent(static function (SupervisorEvent $event) use (&$events, &$readyPids, &$recycleResults, &$statusDuring, $supervisor, $loop): void {
        $events[] = $event->type;
        if ($event->type !== SupervisorEventType::WORKER_READY || $event->pid === null) {
            return;
        }
        $readyPids[] = $event->pid;
        $readyCount = count($readyPids);
        if ($readyCount === 1) {
            $loop->delay(0.01, static function () use (&$recycleResults, &$statusDuring, $supervisor): void {
                $statusDuring = $supervisor->status();
                $recycleResults[] = $supervisor->recycle('workers', 0);
                $recycleResults[] = $supervisor->recycle('workers', 0);
            });
            return;
        }
        if ($readyCount === 2) {
            $pid = $event->pid;
            $loop->delay(0.04, static function () use ($pid): void { posix_kill($pid, SIGKILL); });
            return;
        }
        if ($readyCount === 3) {
            $loop->delay(0.02, static function () use ($supervisor): void { $supervisor->stop(); });
        }
    });

    $supervisor->group(WorkerGroup::callbacks(
        name: 'workers',
        count: 1,
        factory: static function (WorkerContext $context): void {
            $stop = $context->stopStream();
            while (!$context->stopping()) {
                $read = [$stop];
                $write = [];
                $except = [];
                stream_select($read, $write, $except, 1, 0);
                $context->consumeStopWake();
            }
        },
        restartPolicy: new RestartPolicy(maxRestarts: 3, windowSeconds: 2.0, initialBackoffSeconds: 0.001, maxBackoffSeconds: 0.01),
        shutdownTimeoutSeconds: 0.2,
    ));
    $loop->delay(1.0, static function () use ($supervisor): void { $supervisor->stop(true); });
    $supervisor->run();

    expect($recycleResults)->toBe([true, false])
        ->and(count(array_unique($readyPids)))->toBeGreaterThanOrEqual(3)
        ->and($events)->toContain(SupervisorEventType::WORKER_RECYCLE_STARTED)
        ->toContain(SupervisorEventType::WORKER_STOP_REQUESTED)
        ->toContain(SupervisorEventType::WORKER_RESTART_SCHEDULED)
        ->and($statusDuring)->not->toBeNull()
        ->and($statusDuring->masterPid)->toBe(posix_getpid())
        ->and($statusDuring->workerCount)->toBe(1)
        ->and($statusDuring->currentWorkerCount)->toBe(1)
        ->and($statusDuring->readyWorkerCount)->toBe(1)
        ->and($supervisor->status()->workers)->toBe([]);
});

it('isolates lifecycle observer failures and reports their count in status', function (): void {
    $loop = new SelectLoop();
    $supervisor = new Supervisor($loop);
    $observed = [];
    $supervisor->onEvent(static function (SupervisorEvent $event) use (&$observed): void { $observed[] = $event->type; });
    $supervisor->onEvent(static function (): void { throw new RuntimeException('observer failure'); });
    $supervisor->group(WorkerGroup::callbacks(
        'events', 1,
        static function (WorkerContext $context): void { while (!$context->stopping()) { usleep(1_000); } },
        shutdownTimeoutSeconds: 0.1,
    ));
    $loop->delay(0.04, static fn () => $supervisor->stop());
    $supervisor->run();
    $status = $supervisor->status();

    expect($observed)->toContain(SupervisorEventType::SUPERVISOR_STARTING)
        ->toContain(SupervisorEventType::WORKER_SPAWNED)
        ->toContain(SupervisorEventType::WORKER_READY)
        ->toContain(SupervisorEventType::SUPERVISOR_STOPPED)
        ->and($status->lifecycleListenerFailures)->toBeGreaterThanOrEqual(1)
        ->and($status->workerCount)->toBe(0)
        ->and($status->uptimeSeconds)->toBeGreaterThan(0);
});

it('validates recycle targets without affecting running workers', function (): void {
    $supervisor = new Supervisor(new SelectLoop());
    $supervisor->group(WorkerGroup::callbacks('known', 1, static function (): void {}));
    expect(fn () => $supervisor->recycle('missing', 0))->toThrow(LogicException::class)
        ->and(fn () => $supervisor->recycle('known', 1))->toThrow(LogicException::class)
        ->and($supervisor->recycle('known', 0))->toBeFalse();
});


it('keeps live HTTP1 TCP and UDP responsive across crash recycle and rolling reload', function (): void {
    $http = stream_socket_server('tcp://127.0.0.1:0', $httpCode, $httpError);
    $tcp = stream_socket_server('tcp://127.0.0.1:0', $tcpCode, $tcpError);
    $udp = stream_socket_server('udp://127.0.0.1:0', $udpCode, $udpError, STREAM_SERVER_BIND);
    if (!is_resource($http) || !is_resource($tcp) || !is_resource($udp)) {
        throw new RuntimeException('Unable to bind native network lifecycle fixtures.');
    }

    $httpAddress = stream_socket_get_name($http, false);
    $tcpAddress = stream_socket_get_name($tcp, false);
    $udpAddress = stream_socket_get_name($udp, false);
    if (!is_string($httpAddress) || !is_string($tcpAddress) || !is_string($udpAddress)) {
        throw new RuntimeException('Native network lifecycle fixture addresses are incomplete.');
    }

    $loop = new SelectLoop();
    $supervisor = new Supervisor($loop);
    $readyPids = [];
    $replies = [];
    $events = [];
    $supervisor->group(WorkerGroup::callbacks(
        name: 'live-network',
        count: 1,
        factory: static function (WorkerContext $context) use ($http, $tcp, $udp): void {
            stream_set_blocking($http, false);
            stream_set_blocking($tcp, false);
            stream_set_blocking($udp, false);
            $stop = $context->stopStream();
            $context->ready();
            while (!$context->stopping()) {
                $ready = [$http, $tcp, $udp, $stop];
                $write = [];
                $except = [];
                if (stream_select($ready, $write, $except, 0, 50_000) === false) {
                    throw new RuntimeException('Native lifecycle fixture poll failed.');
                }
                foreach ($ready as $socket) {
                    if ($socket === $stop) {
                        $context->consumeStopWake();
                    } elseif ($socket === $udp) {
                        $remote = null;
                        $message = stream_socket_recvfrom($udp, 1_024, 0, $remote);
                        if ($message === 'ping' && is_string($remote)) {
                            stream_socket_sendto($udp, 'pong', 0, $remote);
                        }
                    } else {
                        $connection = stream_socket_accept($socket, 0);
                        if (!is_resource($connection)) {
                            continue;
                        }
                        stream_set_timeout($connection, 1);
                        $incoming = fread($connection, 2_048);
                        if ($socket === $http && is_string($incoming) && str_starts_with($incoming, 'GET /live HTTP/1.1')) {
                            fwrite($connection, "HTTP/1.1 200 OK\r\nContent-Length: 2\r\nConnection: close\r\n\r\nok");
                        } elseif ($socket === $tcp && $incoming === 'ping') {
                            fwrite($connection, 'pong');
                        }
                        fclose($connection);
                    }
                }
            }
        },
        restartPolicy: new RestartPolicy(
            maxRestarts: 4,
            windowSeconds: 3.0,
            initialBackoffSeconds: 0.001,
            maxBackoffSeconds: 0.02,
        ),
        shutdownTimeoutSeconds: 0.2,
    ));

    $exchange = static function (string $address, string $payload, int $length): string {
        $stream = stream_socket_client('tcp://' . $address, $code, $error, 1.0);
        if (!is_resource($stream)) {
            throw new RuntimeException('Real TCP listener unavailable: ' . $error);
        }

        try {
            stream_set_timeout($stream, 1);
            if (fwrite($stream, $payload) !== strlen($payload)) {
                throw new RuntimeException('Incomplete native lifecycle fixture write.');
            }

            $response = '';
            while (strlen($response) < $length && !feof($stream)) {
                $chunk = fread($stream, 2_048);
                if (!is_string($chunk) || $chunk === '') {
                    break;
                }
                $response .= $chunk;
            }

            return $response;
        } finally {
            fclose($stream);
        }
    };

    $supervisor->onEvent(static function (SupervisorEvent $event) use (
        $supervisor,
        $httpAddress,
        $tcpAddress,
        $udpAddress,
        $exchange,
        &$readyPids,
        &$replies,
        &$events,
    ): void {
        $events[] = $event->type;
        if ($event->type === SupervisorEventType::WORKER_RECYCLE_COMPLETED && count($readyPids) === 3) {
            $supervisor->reload();

            return;
        }
        if ($event->type === SupervisorEventType::RELOAD_COMPLETED && count($readyPids) === 4) {
            $supervisor->stop();

            return;
        }
        if ($event->type !== SupervisorEventType::WORKER_READY || $event->pid === null) {
            return;
        }

        $readyPids[] = $event->pid;
        $reply = $exchange($httpAddress, "GET /live HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n", 59);
        if (!str_contains($reply, "200 OK") || !str_ends_with($reply, "\r\n\r\nok")) {
            throw new RuntimeException('Real HTTP/1 listener failed during native worker lifecycle.');
        }
        if ($exchange($tcpAddress, 'ping', 4) !== 'pong') {
            throw new RuntimeException('Real TCP listener failed during native worker lifecycle.');
        }

        $udpClient = stream_socket_client('udp://' . $udpAddress, $code, $error, 1.0);
        if (!is_resource($udpClient)) {
            throw new RuntimeException('Real UDP client could not connect: ' . $error);
        }
        try {
            stream_set_timeout($udpClient, 1);
            fwrite($udpClient, 'ping');
            if (fread($udpClient, 4) !== 'pong') {
                throw new RuntimeException('Real UDP listener failed during native worker lifecycle.');
            }
        } finally {
            fclose($udpClient);
        }
        $replies[] = true;

        if (count($readyPids) === 1) {
            posix_kill($event->pid, SIGKILL);
        } elseif (count($readyPids) === 2) {
            if (!$supervisor->recycle('live-network', 0)) {
                throw new RuntimeException('Manual native worker recycle was rejected.');
            }
        }
    });

    $loop->delay(4.0, static function () use ($supervisor): void {
        $supervisor->stop(true);
    });

    try {
        $supervisor->run();
        expect($replies)->toBe([true, true, true, true])
            ->and(count(array_unique($readyPids)))->toBe(4)
            ->and($events)->toContain(SupervisorEventType::WORKER_RESTART_SCHEDULED)
            ->toContain(SupervisorEventType::WORKER_RECYCLE_STARTED)
            ->toContain(SupervisorEventType::WORKER_RECYCLE_COMPLETED)
            ->toContain(SupervisorEventType::RELOAD_STARTED)
            ->toContain(SupervisorEventType::RELOAD_COMPLETED)
            ->and($supervisor->status()->workers)->toBe([]);

        $status = 0;
        expect(pcntl_waitpid(-1, $status, WNOHANG))->toBe(-1)
            ->and(pcntl_get_last_error())->toBe(PCNTL_ECHILD);
    } finally {
        fclose($http);
        fclose($tcp);
        fclose($udp);
    }
});
