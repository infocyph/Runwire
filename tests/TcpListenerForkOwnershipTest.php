<?php

declare(strict_types=1);

use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Network\TcpListener;

it('permits a prefork bound listener but refuses inherited active event-loop ownership', function (): void {
    expect(function_exists('pcntl_fork'))->toBeTrue();

    $listener = TcpListener::bind('127.0.0.1:0');
    [$reader, $writer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

    $pid = pcntl_fork();
    expect($pid)->not->toBe(-1);

    if ($pid === 0) {
        fclose($reader);
        $loop = new SelectLoop();
        $listener->start($loop, static function (): void {});
        $acceptsInChild = $listener->isAccepting();
        $listener->close();
        fwrite($writer, $acceptsInChild ? '1' : '0');
        fclose($writer);
        pcntl_exec(PHP_BINARY, ['-r', ($acceptsInChild) ? '' : 'throw new RuntimeException("Child fork assertion failed.");']);
        throw new RuntimeException('Unable to replace forked QA child process.');
    }

    fclose($writer);
    try {
        $observed = stream_get_contents($reader);
        pcntl_waitpid($pid, $status);
        expect(pcntl_wifexited($status))->toBeTrue()
            ->and(pcntl_wexitstatus($status))->toBe(0)
            ->and($observed)->toBe('1');

        $loop = new SelectLoop();
        $listener->start($loop, static function (): void {});
        $second = pcntl_fork();
        expect($second)->not->toBe(-1);
        if ($second === 0) {
            $rejected = 0;
            foreach ([
                static fn() => $listener->abortConnections(),
                static fn() => $listener->closeConnectionsGracefully(),
                static fn() => $listener->resumeAccepting(),
                static fn() => $listener->start(new SelectLoop(), static function (): void {}),
            ] as $operation) {
                try {
                    $operation();
                } catch (LogicException) {
                    ++$rejected;
                }
            }
            try {
                $listener->pauseAccepting();
            } catch (LogicException) {
                ++$rejected;
            }
            try {
                $listener->close();
            } catch (LogicException) {
                ++$rejected;
            }
            pcntl_exec(PHP_BINARY, ['-r', ($rejected === 6) ? '' : 'throw new RuntimeException("Child fork assertion failed.");']);
            throw new RuntimeException('Unable to replace forked QA child process.');
        }
        pcntl_waitpid($second, $secondStatus);
        expect(pcntl_wifexited($secondStatus))->toBeTrue()
            ->and(pcntl_wexitstatus($secondStatus))->toBe(0);
        $listener->close();
        expect($loop->diagnostics()->readWatchers)->toBe(0);
    } finally {
        fclose($reader);
        $listener->close();
    }
});
