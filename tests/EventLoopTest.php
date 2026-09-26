<?php

declare(strict_types=1);

use Infocyph\Runwire\Loop\EventLoop;
use Infocyph\Runwire\Loop\LoopFactory;
use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Metrics\DiagnosticsPolicy;

it('uses the portable loop when ext-event is unavailable', function (): void {
    if (EventLoop::supported()) {
        $this->markTestSkipped('ext-event is available on this runner.');
    }

    expect(LoopFactory::native(new DiagnosticsPolicy()))->toBeInstanceOf(SelectLoop::class)
        ->and(fn() => new EventLoop())->toThrow(RuntimeException::class, 'ext-event');
});

it('selects and exercises the scalable backend when ext-event is available', function (): void {
    if (!EventLoop::supported()) {
        $this->markTestSkipped('ext-event is unavailable on this runner.');
    }

    $loop = LoopFactory::native(new DiagnosticsPolicy());
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    if ($pair === false) {
        throw new RuntimeException('Unable to create socket pair.');
    }

    [$reader, $writer] = $pair;
    stream_set_blocking($reader, false);
    stream_set_blocking($writer, false);
    $payload = null;
    $loop->onReadable($reader, function ($stream, int $id) use ($loop, &$payload): void {
        $payload = fread($stream, 5);
        $loop->cancel($id);
    });
    $loop->delay(0.05, function () use ($loop): void {
        $loop->stop();
    });

    fwrite($writer, 'hello');
    $loop->run();

    fclose($reader);
    fclose($writer);

    expect($loop)->toBeInstanceOf(EventLoop::class)
        ->and($payload)->toBe('hello');
});


it('handles more than 1024 descriptors on the scalable backend', function (): void {
    if (!EventLoop::supported()) {
        $this->markTestSkipped('ext-event is unavailable on this runner.');
    }

    $loop = new EventLoop();
    $pairs = [];
    $ready = 0;

    try {
        for ($i = 0; $i < 1_100; ++$i) {
            $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
            if ($pair === false) {
                throw new RuntimeException('Unable to create capacity-test socket pair.');
            }
            [$reader, $writer] = $pair;
            stream_set_blocking($reader, false);
            stream_set_blocking($writer, false);
            $pairs[] = $pair;
            $loop->onReadable($reader, function ($stream, int $id) use ($loop, &$ready): void {
                if (fread($stream, 1) === 'x') {
                    ++$ready;
                }
                $loop->cancel($id);
                if ($ready === 1_100) {
                    $loop->stop();
                }
            });
        }

        foreach ($pairs as [, $writer]) {
            fwrite($writer, 'x');
        }

        $loop->delay(2.0, function () use ($loop): void {
            $loop->stop();
        });
        $loop->run();

        expect($ready)->toBe(1_100);
    } finally {
        foreach ($pairs as [$reader, $writer]) {
            fclose($reader);
            fclose($writer);
        }
    }
});
