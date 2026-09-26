<?php

declare(strict_types=1);

use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Process\Command;
use Infocyph\Runwire\Process\Enum\TerminationReason;
use Infocyph\Runwire\Process\ProcessRunner;

it('runs deferred callbacks in registration order without consuming newly deferred work in the same batch', function (): void {
    $loop = new SelectLoop();
    $events = [];

    $loop->defer(function () use ($loop, &$events): void {
        $events[] = 'first';
        $loop->defer(function () use (&$events): void {
            $events[] = 'third';
        });
    });
    $loop->defer(function () use (&$events): void {
        $events[] = 'second';
    });

    $loop->run();

    expect($events)->toBe(['first', 'second', 'third']);
});

it('stops immediately after a deferred callback without running due timers in the same tick', function (): void {
    $loop = new SelectLoop();
    $events = [];
    $timer = $loop->delay(0.0, function () use (&$events): void {
        $events[] = 'timer';
    });
    $loop->defer(function () use ($loop, &$events): void {
        $events[] = 'deferred';
        $loop->stop();
    });

    $loop->run();

    expect($events)->toBe(['deferred'])
        ->and($loop->cancel($timer))->toBeTrue();
});

it('preserves due timers that remain after an earlier callback stops the loop', function (): void {
    $loop = new SelectLoop();
    $events = [];

    $loop->delay(0.0, function () use ($loop, &$events): void {
        $events[] = 'first';
        $loop->stop();
    });
    $loop->delay(0.0, function () use (&$events): void {
        $events[] = 'second';
    });

    $loop->run();

    $loop->delay(0.01, function () use ($loop): void {
        $loop->stop();
    });
    $loop->run();

    expect($events)->toBe(['first', 'second']);
});

it('records timer lag introduced by deferred callbacks in the same tick', function (): void {
    $loop = new SelectLoop();
    $loop->delay(0.0, static function (): void {});
    $loop->defer(static function (): void {
        usleep(20_000);
    });

    $loop->run();

    expect($loop->diagnostics()->maxLagNanoseconds)->toBeGreaterThanOrEqual(10_000_000);
});

it('runs and cancels a repeating timer from its callback', function (): void {
    $loop = new SelectLoop();
    $runs = 0;

    $loop->repeat(0.001, function (int $id) use ($loop, &$runs): void {
        ++$runs;
        if ($runs === 3) {
            expect($loop->cancel($id))->toBeTrue();
        }
    });

    $loop->run();

    expect($runs)->toBe(3);
});

it('dispatches readable streams and permits self cancellation', function (): void {
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    if ($pair === false) {
        throw new RuntimeException('Unable to create socket pair.');
    }

    [$reader, $writer] = $pair;
    stream_set_blocking($reader, false);
    stream_set_blocking($writer, false);

    $loop = new SelectLoop();
    $payload = null;

    $loop->onReadable($reader, function ($stream, int $id) use ($loop, &$payload): void {
        $payload = fread($stream, 5);
        $loop->cancel($id);
    });

    fwrite($writer, 'hello');
    $loop->run();

    fclose($reader);
    fclose($writer);

    expect($payload)->toBe('hello');
});

it('prunes streams closed before polling', function (): void {
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    if ($pair === false) {
        throw new RuntimeException('Unable to create socket pair.');
    }

    [$reader, $writer] = $pair;
    $loop = new SelectLoop();
    $called = false;

    $loop->onReadable($reader, function () use (&$called): void {
        $called = true;
    });

    fclose($reader);
    fclose($writer);
    $loop->run();

    expect($called)->toBeFalse();
});

it('can stop while idle watchers remain registered', function (): void {
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    if ($pair === false) {
        throw new RuntimeException('Unable to create socket pair.');
    }

    [$reader, $writer] = $pair;
    $loop = new SelectLoop();

    $loop->onReadable($reader, static function (): void {
    });
    $loop->delay(0.001, function () use ($loop): void {
        $loop->stop();
    });

    $loop->run();

    fclose($reader);
    fclose($writer);

    expect(true)->toBeTrue();
});

it('rejects duplicate directional watchers on one stream', function (): void {
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    if ($pair === false) {
        throw new RuntimeException('Unable to create socket pair.');
    }

    [$reader, $writer] = $pair;
    $loop = new SelectLoop();
    $loop->onReadable($reader, static function (): void {
    });

    try {
        $loop->onReadable($reader, static function (): void {
        });
    } finally {
        fclose($reader);
        fclose($writer);
    }
})->throws(InvalidArgumentException::class, 'already has a readable watcher');

it('rejects zero repeating intervals', function (): void {
    (new SelectLoop())->repeat(0.0, static function (): void {
    });
})->throws(InvalidArgumentException::class);

it('removes a repeating timer when its callback throws', function (): void {
    $loop = new SelectLoop();
    $loop->repeat(0.0001, static function (): void {
        throw new RuntimeException('timer failed');
    });

    try {
        $loop->run();
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('timer failed');
    }

    $ran = false;
    $loop->defer(function () use (&$ran): void {
        $ran = true;
    });
    $loop->run();

    expect($ran)->toBeTrue();
});

it('restores loop state after callback failure so the instance can run again', function (): void {
    $loop = new SelectLoop();
    $loop->defer(static function (): void {
        throw new RuntimeException('boom');
    });

    try {
        $loop->run();
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('boom');
    }

    $ran = false;
    $loop->defer(function () use (&$ran): void {
        $ran = true;
    });
    $loop->run();

    expect($ran)->toBeTrue();
});


it('fails fast when stream_select reaches the portable descriptor ceiling', function (): void {
    $autoload = realpath('vendor/autoload.php');
    if (!is_string($autoload)) {
        throw new RuntimeException('Unable to resolve the Composer autoloader.');
    }

    $script = sprintf(<<<'PHP'
require %s;

$loop = new \Infocyph\Runwire\Loop\SelectLoop();
$pairs = [];

for ($index = 0; $index < 600; ++$index) {
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    if ($pair === false) {
        foreach ($pairs as $opened) {
            fclose($opened[0]);
            fclose($opened[1]);
        }
        exit(21);
    }

    [$reader, $writer] = $pair;
    stream_set_blocking($reader, false);
    stream_set_blocking($writer, false);
    $pairs[] = $pair;
    $loop->onReadable($reader, static function (): void {});
}

try {
    $loop->tick();
} catch (\RuntimeException $exception) {
    foreach ($pairs as $opened) {
        fclose($opened[0]);
        fclose($opened[1]);
    }

    exit(str_contains($exception->getMessage(), 'stream_select()') ? 0 : 22);
}

foreach ($pairs as $opened) {
    fclose($opened[0]);
    fclose($opened[1]);
}

exit(23);
PHP, var_export($autoload, true));

    $result = (new ProcessRunner())->run(
        Command::executable(PHP_BINARY, ['-r', $script])
            ->timeout(3.0)
            ->terminationGrace(0.05),
    );

    expect($result->terminationReason)->not->toBe(TerminationReason::TIMEOUT)
        ->and($result->exitCode)->toBe(0);
});
