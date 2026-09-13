<?php

declare(strict_types=1);

use Infocyph\Runwire\Coroutine\CoroutineRuntime;
use Infocyph\Runwire\Coroutine\CoroutineScope;
use Infocyph\Runwire\Loop\SwooleLoop;
use Infocyph\Runwire\Tests\Fixtures\BatchOSwooleReactor;

it('drives Runwire Fibers through a host-owned Swoole reactor bridge', function (): void {
    $reactor = new BatchOSwooleReactor();
    $loop = new SwooleLoop($reactor);
    $runtime = new CoroutineRuntime($loop);
    $events = [];

    $result = $runtime->run(function (CoroutineScope $scope) use (&$events): int {
        $task = $scope->spawn(function () use ($scope, &$events): int {
            $events[] = 'child:start';
            $scope->yieldNow();
            $events[] = 'child:end';

            return 42;
        });
        $scope->sleep(0.001);
        $events[] = 'root:awake';

        return $task->await();
    });

    expect($result)->toBe(42)
        ->and($events)->toBe(['child:start', 'child:end', 'root:awake'])
        ->and($loop->diagnostics()->timersActive)->toBe(0)
        ->and($loop->diagnostics()->deferredBacklog)->toBe(0);
});

it('refuses to drive the Swoole bridge without an active host coroutine', function (): void {
    $loop = new SwooleLoop(new BatchOSwooleReactor(-1));
    $loop->defer(static function (): void {});

    expect(static fn() => $loop->run())
        ->toThrow(LogicException::class, 'requires an active Swoole/OpenSwoole host coroutine');
});
