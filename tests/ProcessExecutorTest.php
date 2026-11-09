<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Tests;

use Infocyph\Runwire\CancellationSource;
use Infocyph\Runwire\Exception\CancelledException;
use Infocyph\Runwire\Executor\ProcessExecutor;
use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\RequestDeadline;
use Infocyph\Runwire\Runtime\Enum\CancellationReason;
use LogicException;
use OverflowException;
use ReflectionProperty;
use RuntimeException;
use Throwable;


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
            expect($value)->toBeNull();
            $error = $failure;
            $executor->close();
        },
        deadline: RequestDeadline::afterSeconds(0.03, hrtime(true)),
    );
    $loop->run();
    expect($error)->toBeInstanceOf(RuntimeException::class);
    if (!$error instanceof RuntimeException) {
        throw new LogicException('Deadline callback did not produce a runtime failure.');
    }
    expect($error->getMessage())->toContain('deadline');
});

it('bounds deferred callbacks while maintaining timer and socket progress', function (): void {
    $loop = new SelectLoop();
    $events = [];
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    if ($pair === false || count($pair) !== 2) {
        throw new \RuntimeException('Socket pair fixture did not return two connected streams.');
    }
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
    expect($errors)->toHaveCount(1);
    $deadlineError = $errors[0] ?? null;
    expect($deadlineError)->toBeInstanceOf(CancelledException::class);
    if (!$deadlineError instanceof CancelledException) {
        throw new LogicException('Inherited deadline did not cancel the worker.');
    }
    expect($deadlineError->reason)->toBe(CancellationReason::DEADLINE_EXCEEDED)
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

        expect($completed)->toHaveCount(1);
        $completion = $completed[0] ?? throw new RuntimeException('Missing inherited-deadline callback.');
        $deadlineError = $completion[1];
        expect($completion[0])->toBeNull()
            ->and($deadlineError)->toBeInstanceOf(CancelledException::class);
        if (!$deadlineError instanceof CancelledException) {
            throw new LogicException('Queued worker did not report the inherited deadline.');
        }
        expect($deadlineError->reason)->toBe(CancellationReason::DEADLINE_EXCEEDED)
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

it('decodes executor results by the top-level operation, never nested JSON argument text', function (): void {
    $loop = new SelectLoop();
    $executor = new ProcessExecutor($loop, 1, 2);
    $results = [];

    try {
        $executor->submit('sleep', ['milliseconds' => 1, 'op' => 'read'], function ($value, $error) use (&$results): void {
            $results[] = [$value, $error];
        });
        $executor->onDrained(static function () use ($executor): void {
            $executor->close();
        });
        $loop->run();

        expect($results)->toBe([[1, null]])
            ->and($executor->diagnostics()['workers'])->toBe(0);
    } finally {
        $executor->close();
    }
});

it('binds every subprocess file operation to a validated path and rejects wrappers', function (): void {
    $file = tempnam(sys_get_temp_dir(), 'runwire-worker-path-');
    expect($file)->toBeString();
    file_put_contents($file, 'guarded-file');
    $loop = new SelectLoop();
    $executor = new ProcessExecutor($loop, 1, 4);
    $results = [];

    try {
        $executor->submit('read', ['path' => 'php://memory', 'offset' => 0, 'length' => 8],
            static function ($value, ?Throwable $error) use (&$results): void {
                $results['wrapper-read'] = [$value, $error];
            });
        $executor->submit('stat', ['path' => 'http://127.0.0.1/secret'],
            static function ($value, ?Throwable $error) use (&$results): void {
                $results['wrapper-stat'] = [$value, $error];
            });
        $executor->submit('read', ['path' => $file, 'offset' => 0, 'length' => 7],
            static function ($value, ?Throwable $error) use (&$results): void {
                $results['safe-read'] = [$value, $error];
            });
        $executor->onDrained(static function () use ($executor): void {
            $executor->close();
        });
        $loop->run();

        $wrapperRead = $results['wrapper-read'] ?? throw new RuntimeException('Missing wrapper read completion.');
        $wrapperStat = $results['wrapper-stat'] ?? throw new RuntimeException('Missing wrapper stat completion.');
        $safeRead = $results['safe-read'] ?? throw new RuntimeException('Missing guarded read completion.');
        expect($wrapperRead[1])->toBeInstanceOf(RuntimeException::class)
            ->and($wrapperStat[1])->toBeInstanceOf(RuntimeException::class)
            ->and($safeRead)->toBe(['guarded', null]);
    } finally {
        $executor->close();
        unlink($file);
    }
});

it('does not execute unsigned or incorrectly signed subprocess requests', function (): void {
    $fixture = tempnam(sys_get_temp_dir(), 'runwire-ipc-protected-');
    expect($fixture)->toBeString();
    file_put_contents($fixture, 'protected');
    $key = str_repeat('a', 64);

    try {
        foreach (['unsigned', 'incorrect-signature'] as $case) {
            $pipes = [];
            $process = proc_open(
                [PHP_BINARY, '-d', 'display_errors=stderr', dirname(__DIR__) . '/tools/executor-worker.php'],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                null,
                ['RUNWIRE_EXECUTOR_IPC_KEY' => $key],
                ['bypass_shell' => true],
            );
            expect(is_resource($process))->toBeTrue();
            if (!is_resource($process)) {
                throw new RuntimeException('Unable to launch signed worker protocol fixture.');
            }
            $payload = json_encode([
                'id' => 1,
                'op' => 'write',
                'args' => ['path' => $fixture, 'data' => base64_encode('corrupt'), 'offset' => 0],
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $packet = $case === 'unsigned' ? $payload : str_repeat('0', 64) . ':' . $payload;
            fwrite($pipes[0], $packet . "\n");
            fclose($pipes[0]);
            $result = stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[2]);
            $status = proc_close($process);
            expect($result)->toContain('"ok":false')
                ->and($result)->toContain('Unauthenticated executor IPC request')
                ->and($status)->toBe(0, (string) $stderr)
                ->and(file_get_contents($fixture))->toBe('protected');
        }
    } finally {
        if (is_file($fixture)) {
            unlink($fixture);
        }
    }
});
