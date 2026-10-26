<?php

declare(strict_types=1);

use Infocyph\Runwire\Http\Client\Internal\Http3ClientAdmissionGate;

it('bounds active multiplexed request streams and grants waiting siblings in FIFO order', function (): void {
    $gate = new Http3ClientAdmissionGate(2, 3);
    expect($gate->enqueue(1))->toBe([])
        ->and($gate->enqueue(2))->toBe([])
        ->and($gate->enqueue(3))->toBe([])
        ->and($gate->start())->toBe([1, 2])
        ->and($gate->activeCount())->toBe(2)
        ->and($gate->pendingCount())->toBe(1);
    expect($gate->enqueue(4))->toBe([])
        ->and($gate->enqueue(5))->toBe([])
        ->and(fn() => $gate->enqueue(6))->toThrow(OverflowException::class)
        ->and($gate->release(1))->toBe([3])
        ->and($gate->release(2))->toBe([4])
        ->and($gate->release(3))->toBe([5])
        ->and($gate->activeCount())->toBe(2)
        ->and($gate->pendingCount())->toBe(0);
    expect($gate->release(4))->toBe([])
        ->and($gate->release(5))->toBe([])
        ->and($gate->activeCount())->toBe(0);
});

it('does not create a native stream for canceled pending admission', function (): void {
    $gate = new Http3ClientAdmissionGate(1, 2);
    expect($gate->enqueue(4))->toBe([])
        ->and($gate->enqueue(8))->toBe([])
        ->and($gate->start())->toBe([4])
        ->and($gate->release(8))->toBe([])
        ->and($gate->enqueue(12))->toBe([])
        ->and($gate->release(4))->toBe([12])
        ->and($gate->release(12))->toBe([]);
});

it('retires only queued siblings during GOAWAY and rejects later admissions', function (): void {
    $gate = new Http3ClientAdmissionGate(2, 3);
    $gate->enqueue(1);
    $gate->enqueue(2);
    $gate->enqueue(3);
    expect($gate->start())->toBe([1, 2])
        ->and($gate->drain())->toBe([3])
        ->and($gate->drain())->toBe([])
        ->and($gate->activeCount())->toBe(2)
        ->and($gate->pendingCount())->toBe(0)
        ->and(fn() => $gate->enqueue(4))->toThrow(RuntimeException::class);
    expect($gate->release(1))->toBe([])
        ->and($gate->release(2))->toBe([])
        ->and($gate->activeCount())->toBe(0);
});

it('rejects malformed duplicate IDs and invalid stream budgets before admission', function (): void {
    expect(fn() => new Http3ClientAdmissionGate(0))->toThrow(InvalidArgumentException::class)
        ->and(fn() => new Http3ClientAdmissionGate(2, 0))->toThrow(InvalidArgumentException::class);
    $gate = new Http3ClientAdmissionGate(2, 2);
    $gate->enqueue(1);
    expect(fn() => $gate->enqueue(1))->toThrow(InvalidArgumentException::class)
        ->and(fn() => $gate->enqueue(0))->toThrow(InvalidArgumentException::class);
});
