<?php

declare(strict_types=1);

use Infocyph\Runwire\Internal\MonotonicTime;
use Infocyph\Runwire\RequestDeadline;

it('converts monotonic durations without rounding positive deadlines down', function (): void {
    expect(MonotonicTime::secondsToNanoseconds(0.0000000001, true))->toBe(1)
        ->and(MonotonicTime::secondsToNanoseconds(0.5))->toBe(500_000_000)
        ->and(RequestDeadline::afterSeconds(0.5, 1_000_000_000)->monotonicNanoseconds)
        ->toBe(1_500_000_000);
});

it('rejects monotonic deadline arithmetic that exceeds the platform integer range', function (): void {
    expect(static fn() => RequestDeadline::afterSeconds(1.0, PHP_INT_MAX))
        ->toThrow(OverflowException::class)
        ->and(static fn() => MonotonicTime::secondsToNanoseconds(INF))
        ->toThrow(InvalidArgumentException::class);
});
