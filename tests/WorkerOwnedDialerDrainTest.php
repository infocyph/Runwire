<?php

declare(strict_types=1);

use Infocyph\Runwire\Executor\ProcessExecutor;
use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Network\AsyncDnsResolver;
use Infocyph\Runwire\Network\Dialer;
use Infocyph\Runwire\Network\HappyEyeballsDialer;
use Infocyph\Runwire\Supervisor\WorkerContext;

it('rejects inherited outbound dial state but keeps the original process owner valid', function (): void {
    $loop = new SelectLoop();
    $executor = new ProcessExecutor($loop, 1, 2);
    $dialer = new Dialer($loop);
    $happy = new HappyEyeballsDialer($loop, $dialer, new AsyncDnsResolver($executor));
    [$reader, $writer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    $pid = pcntl_fork();
    expect($pid)->not->toBe(-1);

    if ($pid === 0) {
        fclose($reader);
        $rejected = 0;
        foreach ([
            static fn() => $dialer->cancel(42),
            static fn() => $dialer->pendingCount(),
            static fn() => $dialer->close(),
            static fn() => $happy->cancel(42),
            static fn() => $happy->pendingCount(),
            static fn() => $happy->close(),
        ] as $operation) {
            try {
                $operation();
            } catch (LogicException) {
                ++$rejected;
            }
        }
        fwrite($writer, (string) $rejected);
        fclose($writer);
        pcntl_exec(PHP_BINARY, ['-r', ($rejected === 6) ? '' : 'throw new RuntimeException("Child fork assertion failed.");']);
        throw new RuntimeException('Unable to replace forked QA child process.');
    }

    fclose($writer);
    try {
        $child = stream_get_contents($reader);
        pcntl_waitpid($pid, $status);
        expect(pcntl_wifexited($status))->toBeTrue()
            ->and(pcntl_wexitstatus($status))->toBe(0)
            ->and($child)->toBe('6')
            ->and($dialer->pendingCount())->toBe(0)
            ->and($happy->pendingCount())->toBe(0);
    } finally {
        fclose($reader);
        $happy->close();
        $dialer->close();
        $executor->close();
    }
});

it('closes real pending dial sockets through worker ownership once', function (): void {
    $server = stream_socket_server('tcp://127.0.0.1:0', $code, $error);
    expect($server)->toBeResource();
    $address = stream_socket_get_name($server, false);
    expect($address)->toBeString();

    $loop = new SelectLoop();
    $dialer = new Dialer($loop);
    $context = new WorkerContext('pending-dials', 0, 1, posix_getpid(), posix_getpid());
    $settled = [];
    try {
        $context->ownDialer($dialer);
        $dialer->connect('tcp://' . $address, static function (mixed $connection, ?Throwable $error) use (&$settled): void {
            $settled[] = [$connection, $error];
        });
        $context->requestStop();
        $context->close();
        expect($settled)->toHaveCount(1)
            ->and($settled[0][0])->toBeNull()
            ->and($settled[0][1])->toBeInstanceOf(Throwable::class)
            ->and($dialer->pendingCount())->toBe(0)
            ->and($loop->diagnostics()->readWatchers)->toBe(0)
            ->and($loop->diagnostics()->writeWatchers)->toBe(0)
            ->and($loop->diagnostics()->timersActive)->toBe(0);
    } finally {
        fclose($server);
        $context->close();
        $dialer->close();
    }
});
