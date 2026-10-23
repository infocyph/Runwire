<?php

declare(strict_types=1);

use Infocyph\Runwire\Executor\ProcessExecutor;
use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\RequestDeadline;
use Infocyph\Runwire\Runtime\Enum\CancellationReason;

it('runs blocking worker jobs outside the event loop and returns correct values', function (): void {
    $loop = new SelectLoop();
    $executor = new ProcessExecutor($loop, 2, 2);
    $seen = [];
    $ticks = 0;
    $loop->delay(0.015, function () use (&$ticks): void {
        ++$ticks;
    });
    $executor->submit('sleep', ['milliseconds' => 100], function ($value, $error) use (&$seen): void {
        expect($error)->toBeNull();
        $seen[] = $value;
        if (count($seen) === 2) {
            $executor->close();
        }
    });
    $executor->submit('sleep', ['milliseconds' => 100], function ($value, $error) use (&$seen, $executor): void {
        expect($error)->toBeNull();
        $seen[] = $value;
        if (count($seen) === 2) {
            $executor->close();
        }
    });
    $loop->run();
    expect($seen)->toBe([100, 100])
        ->and($ticks)->toBe(1)
        ->and($executor->diagnostics()['active'])->toBe(0);
});

it('rejects executor overflow, cancels active and queued jobs exactly once, and drains', function (): void {
    $loop = new SelectLoop();
    $executor = new ProcessExecutor($loop, 1, 1);
    $results = [];
    $running = $executor->submit('sleep', ['milliseconds' => 500], function ($value, $error) use (&$results): void {
        $results[] = [$value, $error];
    });
    $queued = $executor->submit('sleep', ['milliseconds' => 500], function ($value, $error) use (&$results): void {
        $results[] = [$value, $error];
    });
    expect(fn() => $executor->submit('sleep', ['milliseconds' => 10], static function (): void {}))
        ->toThrow(OverflowException::class);
    expect($executor->cancel($queued))->toBeTrue();
    expect($executor->cancel($queued))->toBeFalse();
    $loop->defer(function () use ($executor, $running): void {
        $executor->cancel($running, CancellationReason::WORKER_SHUTDOWN);
        $executor->close();
    });
    $loop->run();
    expect($results)->toHaveCount(2)
        ->and($executor->diagnostics()['cancelled'])->toBe(2);
});

it('does bounded file reads and stat in a subprocess and enforces deadlines', function (): void {
    $file = tempnam(sys_get_temp_dir(), 'runwire-u01-');
    if ($file === false) {
        throw new RuntimeException('Cannot create U01 fixture.');
    }
    file_put_contents($file, 'hello-runwire');
    try {
        $loop = new SelectLoop();
        $executor = new ProcessExecutor($loop, 1, 3);
        $values = [];
        $executor->submit('read', ['path' => $file, 'offset' => 1, 'length' => 5], function ($value, $error) use (&$values): void {
            expect($error)->toBeNull();
            $values['read'] = $value;
        });
        $executor->submit('stat', ['path' => $file], function ($value, $error) use (&$values, $executor): void {
            expect($error)->toBeNull();
            $values['size'] = $value['size'];
            $executor->close();
        });
        $loop->run();
        expect($values)->toBe(['read' => 'ello-', 'size' => 13]);
    } finally {
        unlink($file);
    }

    $loop = new SelectLoop();
    $executor = new ProcessExecutor($loop, 1, 1);
    $error = null;
    $executor->submit(
        'sleep',
        ['milliseconds' => 400],
        function ($value, $failure) use (&$error, $executor): void {
            $error = $failure;
            $executor->close();
        },
        deadline: RequestDeadline::afterSeconds(0.03, hrtime(true)),
    );
    $loop->run();
    expect($error)->toBeInstanceOf(RuntimeException::class)
        ->and($error->getMessage())->toContain('deadline');
});

it('bounds deferred callbacks while maintaining timer and socket progress', function (): void {
    $loop = new SelectLoop();
    $events = [];
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    expect($pair)->not->toBeFalse();
    [$reader, $writer] = $pair;
    stream_set_blocking($reader, false);
    stream_set_blocking($writer, false);
    $loop->onReadable($reader, function ($stream, int $id) use ($loop, &$events): void {
        $events[] = 'read';
        fread($stream, 16);
        $loop->cancel($id);
    });
    fwrite($writer, 'ready');
    for ($i = 0; $i < 1024; ++$i) {
        $loop->defer(function () use (&$events): void {
            $events[] = 'defer';
        });
    }
    $loop->delay(0.0, function () use (&$events): void {
        $events[] = 'timer';
    });
    $loop->run();
    fclose($reader);
    fclose($writer);
    expect($events)->toHaveCount(1026)
        ->and(array_search('read', $events, true))->toBeLessThan(1025)
        ->and(array_search('timer', $events, true))->toBeLessThan(1025);
});
