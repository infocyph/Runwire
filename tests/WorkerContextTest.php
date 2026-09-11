<?php

declare(strict_types=1);

use Infocyph\Runwire\Supervisor\WorkerContext;

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
