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
    $cancelTimer = $loop->delay(0.01, static function () use ($caller): void {
        $caller->cancel(CancellationReason::HOST_CANCELLED);
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
