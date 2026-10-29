<?php

declare(strict_types=1);

use Infocyph\Runwire\Network\Internal\ByteBudget;
use Infocyph\Runwire\Network\Internal\ByteQueue;

it('preserves partial chunk reads, front peeks and exact shared byte accounting', function (): void {
    $budget = new ByteBudget(32);
    $queue = new ByteQueue($budget);
    $queue->append('abc');
    $queue->append('DEFG');
    $queue->append('hij');

    expect($queue->read(2))->toBe('ab')
        ->and($queue->peek())->toBe('cDEFGhij')
        ->and($queue->front(3))->toBe('c')
        ->and($queue->bytes())->toBe(8)
        ->and($budget->used())->toBe(8);

    expect($queue->read(5))->toBe('cDEFG')
        ->and($queue->peek())->toBe('hij')
        ->and($queue->bytes())->toBe(3)
        ->and($budget->used())->toBe(3);

    $queue->append('klm');
    expect($queue->read(128))->toBe('hijklm')
        ->and($queue->read(1))->toBe('')
        ->and($queue->bytes())->toBe(0)
        ->and($budget->used())->toBe(0);
});

it('reads across compaction boundaries without leaking or duplicating bytes', function (): void {
    $budget = new ByteBudget(4_096);
    $queue = new ByteQueue($budget);
    $expected = '';
    for ($i = 0; $i < 300; ++$i) {
        $chunk = sprintf('%03d-', $i);
        $expected .= $chunk;
        $queue->append($chunk);
    }

    $actual = '';
    while (!$queue->isEmpty()) {
        $actual .= $queue->read(91);
        expect($budget->used())->toBe($queue->bytes());
    }

    expect($actual)->toBe($expected)
        ->and($queue->bytes())->toBe(0)
        ->and($queue->peek())->toBe('')
        ->and($budget->used())->toBe(0);

    $queue->append('reuse');
    expect($queue->read(10))->toBe('reuse')
        ->and($budget->used())->toBe(0);
});

it('keeps interleaved read, discard and clear semantics bounded', function (): void {
    $budget = new ByteBudget(10);
    $queue = new ByteQueue($budget);
    $queue->append('12345');
    $queue->append('67890');
    expect($budget->used())->toBe(10)
        ->and(fn() => $queue->append('x'))->toThrow(OverflowException::class);
    expect($queue->read(3))->toBe('123')
        ->and($queue->peek())->toBe('4567890');
    $queue->discard(4);
    expect($queue->read(5))->toBe('890')
        ->and($queue->bytes())->toBe(0)
        ->and($budget->used())->toBe(0);

    $queue->append('abc');
    $queue->clear();
    expect($budget->used())->toBe(0)
        ->and($queue->peek())->toBe('');
});

it('peeks and consumes the active single chunk without leaking budget across partial offsets', function (): void {
    $budget = new ByteBudget(16);
    $queue = new ByteQueue($budget);
    $queue->append('abcdef');
    $queue->append('GHI');

    expect($queue->peek(4))->toBe('abcd')
        ->and($queue->read(2))->toBe('ab')
        ->and($queue->peek(2))->toBe('cd')
        ->and($queue->bytes())->toBe(7)
        ->and($budget->used())->toBe(7);

    expect($queue->read(4))->toBe('cdef')
        ->and($queue->front(2))->toBe('GH')
        ->and($queue->peek(2))->toBe('GH')
        ->and($queue->bytes())->toBe(3)
        ->and($budget->used())->toBe(3);

    expect($queue->read(1))->toBe('G')
        ->and($queue->peek())->toBe('HI')
        ->and($queue->read(2))->toBe('HI')
        ->and($queue->bytes())->toBe(0)
        ->and($budget->used())->toBe(0);

    $queue->append('reused');
    expect($queue->peek(6))->toBe('reused')
        ->and($queue->read(6))->toBe('reused')
        ->and($budget->used())->toBe(0);
});

