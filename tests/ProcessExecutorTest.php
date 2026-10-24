<?php

declare(strict_types=1);

use Infocyph\Runwire\CancellationSource;
use Infocyph\Runwire\Exception\CancelledException;
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
    $executor->submit('sleep', ['milliseconds' => 100], function ($value, $error) use (&$seen, $executor): void {
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
            unset($value);
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

it('terminates failed subprocesses and delivers exactly one failure for every queued job', function (): void {
    $loop = new SelectLoop();
    $executor = new ProcessExecutor($loop, 1, 1, '/bin/false');
    $results = [];
    for ($i = 0; $i < 2; ++$i) {
        $executor->submit('stat', ['path' => '/tmp'], function ($value, $error) use (&$results): void {
            $results[] = $error;
            expect($value)->toBeNull();
        });
    }
    $executor->onDrained(function () use ($executor): void {
        $executor->close();
    });
    $loop->run();
    expect($results)->toHaveCount(2)
        ->and($results[0])->toBeInstanceOf(RuntimeException::class)
        ->and($results[1])->toBeInstanceOf(RuntimeException::class)
        ->and($executor->diagnostics()['workers'])->toBe(0)
        ->and($executor->diagnostics()['failed'])->toBe(2);
});

it('unsubscribes a cancelled job and does not call its completion twice', function (): void {
    $loop = new SelectLoop();
    $executor = new ProcessExecutor($loop, 1, 1);
    $source = new CancellationSource();
    $seen = [];
    $id = $executor->submit(
        'sleep',
        ['milliseconds' => 300],
        function ($value, $error) use (&$seen): void {
            $seen[] = $error;
            expect($value)->toBeNull();
        },
        token: $source->token(),
    );
    expect($source->token()->subscriptionCount())->toBe(1);
    expect($source->cancel(CancellationReason::HOST_CANCELLED))->toBeTrue();
    expect($executor->cancel($id))->toBeFalse();
    $executor->close();
    $loop->run();
    expect($seen)->toHaveCount(1)
        ->and($seen[0])->toBeInstanceOf(CancelledException::class)
        ->and($source->token()->subscriptionCount())->toBe(0);
    $source->dispose();
});

it('honors the earliest inherited token deadline instead of extending the operation', function (): void {
    $loop = new SelectLoop();
    $executor = new ProcessExecutor($loop, 1, 1);
    $source = new CancellationSource(RequestDeadline::afterSeconds(0.04, hrtime(true)));
    $errors = [];
    $executor->submit(
        'sleep',
        ['milliseconds' => 300],
        function ($value, $error) use (&$errors): void {
            expect($value)->toBeNull();
            $errors[] = $error;
        },
        token: $source->token(),
        deadline: RequestDeadline::afterSeconds(1.0, hrtime(true)),
    );
    $executor->onDrained(function () use ($executor): void {
        $executor->close();
    });
    $loop->run();
    expect($errors)->toHaveCount(1)
        ->and($errors[0])->toBeInstanceOf(CancelledException::class)
        ->and($errors[0]->reason)->toBe(CancellationReason::DEADLINE_EXCEEDED)
        ->and($executor->diagnostics()['active'])->toBe(0);
    $source->dispose();
});


it('cancels a queued filesystem read at the inherited token deadline before its worker starts', function (): void {
    $file = tempnam(sys_get_temp_dir(), 'runwire-scoped-file-');
    if (!is_string($file)) {
        throw new RuntimeException('Unable to allocate the off-loop file test fixture.');
    }
    file_put_contents($file, 'deadline-protected');
    $loop = new SelectLoop();
    $executor = new ProcessExecutor($loop, 1, 2);
    $source = new CancellationSource(RequestDeadline::afterSeconds(0.025, hrtime(true)));
    $completed = [];

    try {
        $executor->submit('sleep', ['milliseconds' => 100], static function (): void {});
        $executor->submit(
            'read',
            ['path' => $file, 'offset' => 0, 'length' => 8],
            static function ($value, ?Throwable $error) use (&$completed, $executor): void {
                $completed[] = [$value, $error];
                $executor->close();
            },
            token: $source->token(),
            deadline: RequestDeadline::afterSeconds(1.0, hrtime(true)),
        );
        $loop->run();

        expect($completed)->toHaveCount(1)
            ->and($completed[0][0])->toBeNull()
            ->and($completed[0][1])->toBeInstanceOf(CancelledException::class)
            ->and($completed[0][1]->reason)->toBe(CancellationReason::DEADLINE_EXCEEDED)
            ->and($source->token()->subscriptionCount())->toBe(0)
            ->and($executor->diagnostics()['active'])->toBe(0)
            ->and($executor->diagnostics()['queued'])->toBe(0)
            ->and($loop->diagnostics()->timersActive)->toBe(0)
            ->and($loop->diagnostics()->readWatchers)->toBe(0);
    } finally {
        $executor->close();
        $source->dispose();
        unlink($file);
    }
});


it('keeps a saturated executor queue bounded while accepted off-loop filesystem work completes', function (): void {
    $file = tempnam(sys_get_temp_dir(), 'runwire-quota-');
    if (!is_string($file)) {
        throw new RuntimeException('Cannot allocate the bounded executor file fixture.');
    }
    file_put_contents($file, 'queued-file');
    $loop = new SelectLoop();
    $executor = new ProcessExecutor($loop, 1, 1);
    $values = [];

    try {
        $executor->submit('sleep', ['milliseconds' => 30], static function ($value, ?Throwable $error) use (&$values): void {
            expect($error)->toBeNull();
            $values[] = $value;
        });
        $executor->submit('read', ['path' => $file, 'offset' => 0, 'length' => 11],
            static function ($value, ?Throwable $error) use (&$values): void {
                expect($error)->toBeNull();
                $values[] = $value;
            },
        );
        expect(fn() => $executor->submit('stat', ['path' => $file], static function (): void {}))
            ->toThrow(OverflowException::class);

        $executor->onDrained(static function () use ($executor): void {
            $executor->close();
        });
        $loop->run();

        expect($values)->toBe([30, 'queued-file'])
            ->and($executor->diagnostics()['submitted'])->toBe(2)
            ->and($executor->diagnostics()['completed'])->toBe(2)
            ->and($executor->diagnostics()['queued'])->toBe(0)
            ->and($executor->diagnostics()['active'])->toBe(0)
            ->and($loop->diagnostics()->readWatchers)->toBe(0)
            ->and($loop->diagnostics()->writeWatchers)->toBe(0)
            ->and($loop->diagnostics()->timersActive)->toBe(0);
    } finally {
        $executor->close();
        unlink($file);
    }
});


it('compacts cancelled queue tombstones under repeated bounded admission', function (): void {
    $loop = new SelectLoop();
    $executor = new ProcessExecutor($loop, 1, 1);
    $resultCount = 0;
    $pending = new ReflectionProperty($executor, 'pending');

    try {
        $first = $executor->submit('sleep', ['milliseconds' => 100],
            static function () use (&$resultCount): void {
                ++$resultCount;
            },
        );

        for ($i = 0; $i < 512; ++$i) {
            $queued = $executor->submit('stat', ['path' => sys_get_temp_dir()],
                static function () use (&$resultCount): void {
                    ++$resultCount;
                },
            );
            expect($executor->cancel($queued))->toBeTrue();
        }

        expect($pending->getValue($executor)->count())->toBeLessThanOrEqual(36)
            ->and($executor->diagnostics()['queued'])->toBe(1)
            ->and($executor->diagnostics()['submitted'])->toBe(513)
            ->and($resultCount)->toBe(512);

        expect($executor->cancel($first))->toBeTrue();
        $executor->close();
        $loop->run();

        expect($resultCount)->toBe(513)
            ->and($executor->diagnostics()['queued'])->toBe(0)
            ->and($executor->diagnostics()['active'])->toBe(0)
            ->and($loop->diagnostics()->readWatchers)->toBe(0)
            ->and($loop->diagnostics()->writeWatchers)->toBe(0)
            ->and($loop->diagnostics()->timersActive)->toBe(0);
    } finally {
        $executor->close();
    }
});
