<?php

declare(strict_types=1);

use Infocyph\Runwire\CancellationSource;
use Infocyph\Runwire\Coroutine\CoroutineRuntime;
use Infocyph\Runwire\Coroutine\CoroutineScope;
use Infocyph\Runwire\Coroutine\Network\AsyncDialer;
use Infocyph\Runwire\Executor\ProcessExecutor;
use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Network\AsyncDnsResolver;
use Infocyph\Runwire\Network\Dialer;
use Infocyph\Runwire\Network\DialOptions;
use Infocyph\Runwire\Network\HappyEyeballsDialer;
use Infocyph\Runwire\Runtime\Enum\CancellationReason;

it('cancels a queued DNS race before a late worker answer and invokes completion once', function (): void {
    $loop = new SelectLoop();
    $executor = new ProcessExecutor($loop, 1, 8);
    $racer = new HappyEyeballsDialer($loop, new Dialer($loop), new AsyncDnsResolver($executor));
    $source = new CancellationSource();
    $events = [];
    $executor->submit('sleep', ['milliseconds' => 90], static function (): void {});
    $racer->connect('tcp://localhost:44444', function ($connection, $error) use (&$events): void {
        $events[] = [$connection, $error];
    }, new DialOptions(cancellation: $source->token()));
    $source->cancel(CancellationReason::HOST_CANCELLED);
    expect($events)->toHaveCount(1)
        ->and($events[0][0])->toBeNull()
        ->and($events[0][1])->toBeInstanceOf(Throwable::class)
        ->and($racer->pendingCount())->toBe(0)
        ->and($source->token()->subscriptionCount())->toBe(0);
    $executor->close();
    $loop->run();
    expect($events)->toHaveCount(1)
        ->and($loop->diagnostics()->readWatchers)->toBe(0)
        ->and($loop->diagnostics()->writeWatchers)->toBe(0)
        ->and($loop->diagnostics()->timersActive)->toBe(0);
    $source->dispose();
});

it('enforces overall deadline while DNS waits behind a blocking worker', function (): void {
    $loop = new SelectLoop();
    $executor = new ProcessExecutor($loop, 1, 8);
    $racer = new HappyEyeballsDialer($loop, new Dialer($loop), new AsyncDnsResolver($executor));
    $events = [];
    $executor->submit('sleep', ['milliseconds' => 80], static function (): void {});
    $racer->connect('tcp://localhost:44444', function ($connection, $error) use (&$events, $loop): void {
        $events[] = [$connection, $error];
        $loop->stop();
    }, new DialOptions(connectTimeoutSeconds: 0.015));
    $loop->run();
    expect($events)->toHaveCount(1)
        ->and($events[0][0])->toBeNull()
        ->and($events[0][1])->toBeInstanceOf(RuntimeException::class)
        ->and($racer->pendingCount())->toBe(0);
    $executor->close();
    expect($loop->diagnostics()->timersActive)->toBe(0);
});

it('bounds queued DNS races and address fan-out without accepting extra sockets', function (): void {
    $loop = new SelectLoop();
    $executor = new ProcessExecutor($loop, 1, 8);
    $dialer = new Dialer($loop, 2);
    $racer = new HappyEyeballsDialer($loop, $dialer, new AsyncDnsResolver($executor), 2);
    $events = 0;
    $callback = function () use (&$events): void {
        ++$events;
    };
    $first = $racer->connect('tcp://localhost:80', $callback);
    $second = $racer->connect('tcp://localhost:80', $callback);
    expect($racer->pendingCount())->toBe(2)
        ->and(fn() => $racer->connect('tcp://localhost:80', $callback))->toThrow(OverflowException::class)
        ->and(fn() => $racer->connectResolved(array_fill(0, 17, '127.0.0.1'), 80, $callback))
        ->toThrow(InvalidArgumentException::class);
    expect($racer->cancel($first))->toBeTrue()
        ->and($racer->cancel($second))->toBeTrue()
        ->and($events)->toBe(2)
        ->and($dialer->pendingCount())->toBe(0)
        ->and($racer->pendingCount())->toBe(0);
    $executor->close();
    $loop->run();
    expect($loop->diagnostics()->readWatchers)->toBe(0)
        ->and($loop->diagnostics()->writeWatchers)->toBe(0);
});

it('provides a scoped Fiber awaiting adapter without duplicating the connector', function (): void {
    $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $message);
    if (!is_resource($server)) {
        throw new RuntimeException($message);
    }
    $address = stream_socket_get_name($server, false);
    expect($address)->toBeString();
    $loop = new SelectLoop();
    $executor = new ProcessExecutor($loop, 1, 4);
    $dialer = new Dialer($loop);
    $racer = new HappyEyeballsDialer($loop, $dialer, new AsyncDnsResolver($executor));
    $runtime = new CoroutineRuntime($loop);
    $visited = $runtime->run(function (CoroutineScope $scope) use ($racer, $address): bool {
        $connection = (new AsyncDialer($racer, $scope))->connect('tcp://' . $address);
        $connection->abort();

        return true;
    });
    expect($visited)->toBeTrue()
        ->and($racer->pendingCount())->toBe(0)
        ->and($dialer->pendingCount())->toBe(0);
    $peer = stream_socket_accept($server, 1.0);
    expect($peer)->toBeResource();
    fclose($peer);
    fclose($server);
    $executor->close();
    expect($loop->diagnostics()->readWatchers)->toBe(0)
        ->and($loop->diagnostics()->writeWatchers)->toBe(0)
        ->and($loop->diagnostics()->timersActive)->toBe(0);
});

it('honors an already-cancelled per-call token without registering dial work', function (): void {
    $loop = new SelectLoop();
    $executor = new ProcessExecutor($loop, 1, 4);
    $dialer = new Dialer($loop);
    $racer = new HappyEyeballsDialer($loop, $dialer, new AsyncDnsResolver($executor));
    $caller = new CancellationSource();
    $caller->cancel(CancellationReason::HOST_CANCELLED);
    $runtime = new CoroutineRuntime($loop);

    try {
        expect(fn() => $runtime->run(
            static fn(CoroutineScope $scope) => (new AsyncDialer($racer, $scope))->connect(
                'tcp://localhost:44444',
                new DialOptions(cancellation: $caller->token()),
            ),
        ))->toThrow(\Infocyph\Runwire\Exception\CancelledException::class);
        expect($racer->pendingCount())->toBe(0)
            ->and($dialer->pendingCount())->toBe(0)
            ->and($caller->token()->subscriptionCount())->toBe(0);
    } finally {
        $executor->close();
        $caller->dispose();
    }
});

it('cancels an in-flight scoped hostname dial on caller cancellation without leaking watchers', function (): void {
    $loop = new SelectLoop();
    $executor = new ProcessExecutor($loop, 1, 8);
    $dialer = new Dialer($loop);
    $racer = new HappyEyeballsDialer($loop, $dialer, new AsyncDnsResolver($executor));
    $caller = new CancellationSource();
    $runtime = new CoroutineRuntime($loop);
    // Hold the worker so the hostname request is still pending at cancellation.
    $executor->submit('sleep', ['milliseconds' => 90], static function (): void {});
    $cancelTimer = $loop->delay(0.01, static function () use ($caller, $executor): void {
        $caller->cancel(CancellationReason::HOST_CANCELLED);
        // Persistent worker readiness remains registered until the executor is
        // closed; release it before a standalone CoroutineRuntime can finish.
        $executor->close();
    });

    try {
        expect(fn() => $runtime->run(
            static fn(CoroutineScope $scope) => (new AsyncDialer($racer, $scope))->connect(
                'tcp://localhost:44444',
                new DialOptions(cancellation: $caller->token()),
            ),
        ))->toThrow(\Infocyph\Runwire\Exception\CancelledException::class);
        expect($racer->pendingCount())->toBe(0)
            ->and($dialer->pendingCount())->toBe(0)
            ->and($caller->token()->subscriptionCount())->toBe(0);
    } finally {
        $loop->cancel($cancelTimer);
        $executor->close();
        $caller->dispose();
    }

    expect($loop->diagnostics()->readWatchers)->toBe(0)
        ->and($loop->diagnostics()->writeWatchers)->toBe(0)
        ->and($loop->diagnostics()->timersActive)->toBe(0);
});


it('keeps an external cancellation subscription while honoring request scope shutdown', function (): void {
    $loop = new SelectLoop();
    $executor = new ProcessExecutor($loop, 1, 8);
    $dialer = new Dialer($loop);
    $racer = new HappyEyeballsDialer($loop, $dialer, new AsyncDnsResolver($executor));
    $caller = new CancellationSource();
    $context = \Infocyph\Runwire\RequestContext::standalone();
    $runtime = new CoroutineRuntime($loop);
    $executor->submit('sleep', ['milliseconds' => 90], static function (): void {});
    $cancelTimer = $loop->delay(0.01, static function () use ($context, $executor): void {
        $context->cancel(CancellationReason::HOST_CANCELLED);
        $executor->close();
    });

    try {
        expect(fn() => $runtime->runRequest(
            $context,
            static fn(CoroutineScope $scope) => (new AsyncDialer($racer, $scope))->connect(
                'tcp://localhost:44444',
                new DialOptions(cancellation: $caller->token()),
            ),
        ))->toThrow(\Infocyph\Runwire\Exception\CancelledException::class);
        expect($racer->pendingCount())->toBe(0)
            ->and($dialer->pendingCount())->toBe(0)
            ->and($caller->token()->subscriptionCount())->toBe(0);
    } finally {
        $loop->cancel($cancelTimer);
        $executor->close();
        $caller->dispose();
    }

    expect($loop->diagnostics()->readWatchers)->toBe(0)
        ->and($loop->diagnostics()->writeWatchers)->toBe(0)
        ->and($loop->diagnostics()->timersActive)->toBe(0);
});


it('closes a successfully dialed connection when its coroutine owner exits normally', function (): void {
    $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $message);
    if (!is_resource($server)) {
        throw new RuntimeException($message);
    }
    $address = stream_socket_get_name($server, false);
    expect($address)->toBeString();
    $loop = new SelectLoop();
    $executor = new ProcessExecutor($loop, 1, 4);
    $dialer = new Dialer($loop);
    $racer = new HappyEyeballsDialer($loop, $dialer, new AsyncDnsResolver($executor));
    $runtime = new CoroutineRuntime($loop);

    try {
        $adapter = $runtime->run(static function (CoroutineScope $scope) use ($racer, $address) {
            return (new AsyncDialer($racer, $scope))->connect('tcp://' . $address);
        });
        expect($adapter->state())->toBe(\Infocyph\Runwire\Network\Enum\ConnectionState::CLOSED)
            ->and($adapter->closeReason())->toBe(\Infocyph\Runwire\Network\Enum\CloseReason::LOCAL_ABORT)
            ->and($racer->pendingCount())->toBe(0)
            ->and($dialer->pendingCount())->toBe(0);
        $peer = stream_socket_accept($server, 1.0);
        expect($peer)->toBeResource();
        fclose($peer);
    } finally {
        $executor->close();
        fclose($server);
    }
    expect($loop->diagnostics()->readWatchers)->toBe(0)
        ->and($loop->diagnostics()->writeWatchers)->toBe(0)
        ->and($loop->diagnostics()->timersActive)->toBe(0);
});


it('enforces the inner scope deadline while DNS is queued behind an external worker', function (): void {
    $loop = new SelectLoop();
    $executor = new ProcessExecutor($loop, 1, 8);
    $dialer = new Dialer($loop);
    $racer = new HappyEyeballsDialer($loop, $dialer, new AsyncDnsResolver($executor));
    $runtime = new CoroutineRuntime($loop);
    $executor->submit('sleep', ['milliseconds' => 90], static function (): void {});
    // The process executor is an external loop owner. Retire it after the
    // nested deadline so standalone runtime driving can become idle.
    $workerStopTimer = $loop->delay(0.06, static function () use ($executor): void {
        $executor->close();
    });

    try {
        $failure = null;

        try {
            $runtime->run(
                static fn(CoroutineScope $scope) => $scope->withDeadline(
                    \Infocyph\Runwire\RequestDeadline::afterSeconds(0.02, hrtime(true)),
                    static fn(CoroutineScope $inner) => (new AsyncDialer($racer, $inner))->connect(
                        'tcp://localhost:44444',
                        new DialOptions(connectTimeoutSeconds: 1.0),
                    ),
                ),
            );
        } catch (Throwable $caught) {
            $failure = $caught;
        }

        expect($failure)->toBeInstanceOf(Throwable::class);
        if ($failure instanceof \Infocyph\Runwire\Exception\CancelledException) {
            expect($failure->reason)->toBe(CancellationReason::DEADLINE_EXCEEDED);
        } else {
            expect($failure)->toBeInstanceOf(RuntimeException::class)
                ->and($failure->getMessage())->toContain('deadline');
        }
        expect($racer->pendingCount())->toBe(0)
            ->and($dialer->pendingCount())->toBe(0)
            ->and($runtime->activeTaskCount())->toBe(0);
    } finally {
        $loop->cancel($workerStopTimer);
        $executor->close();
    }

    expect($loop->diagnostics()->readWatchers)->toBe(0)
        ->and($loop->diagnostics()->writeWatchers)->toBe(0)
        ->and($loop->diagnostics()->timersActive)->toBe(0)
        ->and($loop->diagnostics()->deferredBacklog)->toBe(0);
});


it('rejects excess hostname races without enqueuing another DNS job', function (): void {
    $loop = new SelectLoop();
    $executor = new ProcessExecutor($loop, 1, 4);
    $dialer = new Dialer($loop);
    $racer = new HappyEyeballsDialer(
        $loop,
        $dialer,
        new AsyncDnsResolver($executor),
        maxPending: 1,
    );
    $events = [];
    $executor->submit('sleep', ['milliseconds' => 90], static function (): void {});
    $first = $racer->connect('tcp://localhost:44444', static function ($connection, $error) use (&$events): void {
        $events[] = [$connection, $error];
    });

    try {
        expect(fn() => $racer->connect(
            'tcp://localhost:44444',
            static function (): void {},
        ))->toThrow(OverflowException::class);
        expect($racer->pendingCount())->toBe(1);
        $racer->cancel($first);
        expect($racer->pendingCount())->toBe(0)
            ->and($dialer->pendingCount())->toBe(0)
            ->and($events)->toHaveCount(1)
            ->and($events[0][0])->toBeNull()
            ->and($events[0][1])->toBeInstanceOf(
                \Infocyph\Runwire\Exception\CancelledException::class,
            );
    } finally {
        $executor->close();
    }

    expect($loop->diagnostics()->readWatchers)->toBe(0)
        ->and($loop->diagnostics()->writeWatchers)->toBe(0)
        ->and($loop->diagnostics()->timersActive)->toBe(0);
});


it('cancels pending child DNS dialing when its structured parent fails', function (): void {
    $loop = new SelectLoop();
    $executor = new ProcessExecutor($loop, 1, 8);
    $dialer = new Dialer($loop);
    $racer = new HappyEyeballsDialer($loop, $dialer, new AsyncDnsResolver($executor));
    $runtime = new CoroutineRuntime($loop);
    $executor->submit('sleep', ['milliseconds' => 90], static function (): void {});
    $stopWorker = $loop->delay(0.06, static function () use ($executor): void {
        $executor->close();
    });

    try {
        expect(fn() => $runtime->run(static function (CoroutineScope $scope) use ($racer): void {
            $scope->spawn(static function () use ($racer, $scope): void {
                (new AsyncDialer($racer, $scope))->connect('tcp://localhost:44444');
            });
            $scope->yieldNow();
            throw new RuntimeException('synthetic scope failure');
        }))->toThrow(RuntimeException::class, 'synthetic scope failure');

        expect($runtime->activeTaskCount())->toBe(0)
            ->and($racer->pendingCount())->toBe(0)
            ->and($dialer->pendingCount())->toBe(0);
    } finally {
        $loop->cancel($stopWorker);
        $executor->close();
    }
    expect($loop->diagnostics()->readWatchers)->toBe(0)
        ->and($loop->diagnostics()->writeWatchers)->toBe(0)
        ->and($loop->diagnostics()->timersActive)->toBe(0)
        ->and($loop->diagnostics()->deferredBacklog)->toBe(0);
});
