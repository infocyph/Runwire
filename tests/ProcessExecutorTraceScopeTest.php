<?php

declare(strict_types=1);

use Infocyph\Runwire\Executor\ProcessExecutor;
use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Tracing\TraceContext;
use Infocyph\Runwire\Tracing\TraceScope;

it('preserves explicitly owned trace scope across worker completion and caller cancellation', function (): void {
    $loop = new SelectLoop();
    $scope = new TraceScope();
    $executor = new ProcessExecutor($loop, 1, 3, traceScope: $scope);
    $first = new TraceContext(str_repeat('a', 32), str_repeat('b', 16));
    $second = new TraceContext(str_repeat('c', 32), str_repeat('d', 16));
    $captured = [];

    $scope->run($first, function () use ($executor, $scope, &$captured): void {
        $executor->submit('sleep', ['milliseconds' => 25], function ($value, $error) use ($scope, &$captured): void {
            $captured['first'] = [$scope->current(), $value, $error];
        });
    });
    $scope->run($second, function () use ($executor, $scope, &$captured): void {
        $executor->submit('sleep', ['milliseconds' => 25], function ($value, $error) use ($scope, &$captured, $executor): void {
            $captured['second'] = [$scope->current(), $value, $error];
            $executor->close();
        });
    });

    expect($scope->current())->toBeNull();
    $loop->run();
    expect($captured['first'][0])->toBe($first)
        ->and($captured['second'][0])->toBe($second)
        ->and($captured['first'][1])->toBe(25)
        ->and($captured['second'][1])->toBe(25)
        ->and($captured['first'][2])->toBeNull()
        ->and($captured['second'][2])->toBeNull()
        ->and($scope->current())->toBeNull()
        ->and($executor->diagnostics()['workers'])->toBe(0);
});

it('retains trace context for cancelled queued executor completions without leaking it afterward', function (): void {
    $loop = new SelectLoop();
    $scope = new TraceScope();
    $executor = new ProcessExecutor($loop, 1, 2, traceScope: $scope);
    $trace = new TraceContext(str_repeat('1', 32), str_repeat('2', 16));
    $seen = [];
    $scope->run($trace, function () use ($executor, $scope, &$seen): void {
        $id = $executor->submit('sleep', ['milliseconds' => 200], function ($value, $error) use ($scope, &$seen): void {
            $seen[] = [$scope->current(), $error, $value];
        });
        expect($executor->cancel($id))->toBeTrue();
    });
    $executor->close();

    expect($seen)->toHaveCount(1)
        ->and($seen[0][0])->toBe($trace)
        ->and($seen[0][1])->toBeInstanceOf(Throwable::class)
        ->and($seen[0][2])->toBeNull()
        ->and($scope->current())->toBeNull();
});
