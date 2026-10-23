<?php

declare(strict_types=1);

use Infocyph\Runwire\Loop\EventLoop;
use Infocyph\Runwire\Loop\LoopFactory;
use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Loop\SwooleLoop;
use Infocyph\Runwire\Metrics\DiagnosticsPolicy;
use Infocyph\Runwire\Tests\Fixtures\BatchOSwooleReactor;

it('unregisters read/write watchers and deferred work exactly once on every backend', function (): void {
    $loops = [
        'portable select' => new SelectLoop(),
        'host reactor bridge' => new SwooleLoop(new BatchOSwooleReactor()),
    ];
    if (EventLoop::supported()) {
        $loops['native ext-event'] = new EventLoop();
    } else {
        expect(LoopFactory::native(new DiagnosticsPolicy()))->toBeInstanceOf(SelectLoop::class);
    }

    foreach ($loops as $name => $loop) {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($pair === false) {
            throw new RuntimeException('Cannot allocate local watcher test sockets: ' . $name);
        }
        [$reader, $writer] = $pair;
        stream_set_blocking($reader, false);
        stream_set_blocking($writer, false);

        $called = 0;
        $read = $loop->onReadable($reader, static function () use (&$called): void {
            ++$called;
        });
        $write = $loop->onWritable($writer, static function () use (&$called): void {
            ++$called;
        });
        $deferred = $loop->defer(static function () use (&$called): void {
            ++$called;
        });
        expect($loop->cancel($read))->toBeTrue();
        expect($loop->cancel($read))->toBeFalse();
        expect($loop->cancel($write))->toBeTrue();
        expect($loop->cancel($write))->toBeFalse();
        expect($loop->cancel($deferred))->toBeTrue();
        expect($loop->cancel($deferred))->toBeFalse();
        expect($called)->toBe(0);
        $loop->run();
        expect($called)->toBe(0);
        fclose($reader);
        fclose($writer);
    }
});

it('dispatches one real socket read without a stale watcher on both native loop implementations', function (): void {
    $loops = [new SelectLoop()];
    if (EventLoop::supported()) {
        $loops[] = new EventLoop();
    }

    foreach ($loops as $loop) {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($pair === false) {
            throw new RuntimeException('Cannot allocate real native loop socket pair.');
        }
        [$reader, $writer] = $pair;
        stream_set_blocking($reader, false);
        stream_set_blocking($writer, false);
        $seen = [];
        $loop->onReadable($reader, static function ($stream, int $watch) use ($loop, &$seen): void {
            $seen[] = fread($stream, 5);
            $loop->cancel($watch);
        });
        fwrite($writer, 'hello');
        $loop->run();
        expect($seen)->toBe(['hello']);
        fclose($reader);
        fclose($writer);
    }
});
