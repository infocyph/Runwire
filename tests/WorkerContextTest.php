<?php

declare(strict_types=1);

use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Supervisor\Enum\ShutdownReason;
use Infocyph\Runwire\Supervisor\WorkerContext;
use Infocyph\Runwire\Supervisor\WorkerRecyclePolicy;

it('wakes the worker control flow when stop is requested', function (): void {
    [$readyParent, $readyChild] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    $context = new WorkerContext('test', 0, 1, posix_getpid(), posix_getppid(), $readyChild);
    $stop = $context->stopStream();

    $context->requestStop();
    $read = [$stop];
    $write = [];
    $except = [];
    $ready = stream_select($read, $write, $except, 0, 100_000);

    expect($context->stopping())->toBeTrue()->and($ready)->toBe(1);
    $context->consumeStopWake();
    $context->ready();
    expect(fread($readyParent, 1))->toBe('R');

    $context->close();
    fclose($readyParent);
});

it('requests a planned stop when the logical request recycle budget is reached', function (): void {
    [$readyParent, $readyChild] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    $context = new WorkerContext(
        'test',
        0,
        1,
        posix_getpid(),
        posix_getppid(),
        $readyChild,
        new WorkerRecyclePolicy(maxRequests: 1),
    );
    $stop = $context->stopStream();

    expect($context->recordRequestCompleted())->toBeTrue()
        ->and($context->requestsTotal())->toBe(1)
        ->and($context->recycling())->toBeTrue()
        ->and($context->stopping())->toBeTrue();

    $read = [$stop];
    $write = [];
    $except = [];
    expect(stream_select($read, $write, $except, 0, 100_000))->toBe(1);

    $context->consumeStopWake();
    $context->close();
    fclose($readyParent);
});


it('observes supervisor stop control through the attached worker loop', function (): void {
    [$readyParent, $readyChild] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    $context = new WorkerContext('test', 0, 1, posix_getpid(), posix_getppid(), $readyChild);
    $loop = new SelectLoop();
    $context->attachLoop($loop);
    $loop->onReadable(
        $context->stopStream(),
        static function () use ($context, $loop): void {
            $context->consumeStopWake();
            $loop->stop();
        },
    );
    $loop->delay(0.5, static function () use ($loop): void {
        $loop->stop();
    });

    fwrite($readyParent, 'S:' . ShutdownReason::SUPERVISOR_STOP->value . "\n");
    $loop->run();

    expect($context->stopping())->toBeTrue()
        ->and($context->shutdownReason())->toBe(ShutdownReason::SUPERVISOR_STOP);

    $context->close();
    fclose($readyParent);
});

it('coalesces sustained worker request activity without saturating the lifecycle channel', function (): void {
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    expect($pair)->toBeArray();
    [$readyParent, $readyChild] = $pair;
    stream_set_blocking($readyParent, false);
    $context = new WorkerContext('high-rps', 0, 1, posix_getpid(), posix_getppid(), $readyChild);
    $loop = new SelectLoop();

    try {
        $context->attachLoop($loop);
        $context->ready();
        expect(stream_get_contents($readyParent))->toBe("R\n");

        for ($request = 0; $request < 50_000; ++$request) {
            $context->recordRequestStarted();
            $context->recordRequestCompleted();
        }
        // The parent is intentionally NOT drained during the burst. Without
        // lifecycle coalescing, 100,000 B/I frames overflow the nonblocking pipe.
        expect($context->requestsTotal())->toBe(50_000)
            ->and(stream_get_contents($readyParent))->toBe("B\n");

        $loop->delay(0.12, static function () use ($loop): void {
            $loop->stop();
        });
        $loop->run();
        expect(stream_get_contents($readyParent))->toBe("I\n");

        $context->recordRequestStarted();
        $context->recordRequestCompleted();
        expect(stream_get_contents($readyParent))->toBe("B\n");

        $loop->delay(0.12, static function () use ($loop): void {
            $loop->stop();
        });
        $loop->run();
        expect(stream_get_contents($readyParent))->toBe("I\n");
    } finally {
        $context->close();
        fclose($readyParent);
    }
});
