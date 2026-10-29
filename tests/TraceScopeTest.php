<?php

declare(strict_types=1);

use Infocyph\Runwire\Tracing\TraceContext;
use Infocyph\Runwire\Tracing\TraceScope;

it('propagates a scoped trace into wrapped child Fibers without cross-fiber leaks', function (): void {
    $scope = new TraceScope();
    $trace = new TraceContext(str_repeat('a', 32), str_repeat('b', 16));
    $seen = [];
    expect($scope->current())->toBeNull();
    $scope->run($trace, function () use ($scope, &$seen, $trace): void {
        $seen[] = $scope->current();
        $task = $scope->wrap(function (string $tag) use ($scope, &$seen): void {
            $seen[$tag] = $scope->current();
            Fiber::suspend();
            $seen[$tag . '-resumed'] = $scope->current();
        });
        $fiber = new Fiber(static fn() => $task('child'));
        $fiber->start();
        $seen[] = $scope->current();
        $fiber->resume();
        $seen[] = $scope->current();
        expect($scope->current())->toBe($trace);
    });

    expect($scope->current())->toBeNull()
        ->and($seen['child'])->toBe($trace)
        ->and($seen['child-resumed'])->toBe($trace)
        ->and($seen[0])->toBe($trace);
});

it('restores tracing scopes after nested exceptions without contaminating later callbacks', function (): void {
    $scope = new TraceScope();
    $outer = new TraceContext(str_repeat('a', 32), str_repeat('b', 16));
    $inner = new TraceContext(str_repeat('c', 32), str_repeat('d', 16));
    $scope->run($outer, function () use ($scope, $inner, $outer): void {
        expect(fn() => $scope->run($inner, static function (): never {
            throw new RuntimeException('isolate failure');
        }))->toThrow(RuntimeException::class);
        expect($scope->current())->toBe($outer);
    });
    expect($scope->current())->toBeNull();
});

it('captures only explicitly requested parent context in deferred callbacks', function (): void {
    $scope = new TraceScope();
    $trace = new TraceContext(str_repeat('a', 32), str_repeat('b', 16));
    $callbacks = [];
    $scope->run($trace, function () use ($scope, &$callbacks): void {
        $callbacks[] = $scope->wrap(fn() => $scope->current());
    });
    $callbacks[] = $scope->wrap(fn() => $scope->current());
    expect($callbacks[0]())->toBe($trace)
        ->and($callbacks[1]())->toBeNull()
        ->and($scope->current())->toBeNull();
});
