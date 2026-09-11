<?php

declare(strict_types=1);

use Infocyph\Runwire\Loop\SelectLoop;

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
