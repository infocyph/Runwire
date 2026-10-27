<?php

declare(strict_types=1);

use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Network\QuicClientSession;

it('does not let a forked worker inherit ownership of a native QUIC session', function (): void {
    expect(function_exists('pcntl_fork'))->toBeTrue();

    $loop = new SelectLoop();
    $session = new QuicClientSession($loop, 2);
    [$reader, $writer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    $pid = pcntl_fork();
    expect($pid)->not->toBe(-1);

    if ($pid === 0) {
        fclose($reader);
        $rejected = 0;
        try {
            $session->active();
        } catch (LogicException) {
            ++$rejected;
        }
        try {
            $session->dispose();
        } catch (LogicException) {
            ++$rejected;
        }
        fwrite($writer, (string) $rejected);
        fclose($writer);
        pcntl_exec(PHP_BINARY, ['-r', ($rejected === 2) ? '' : 'throw new RuntimeException("Child fork assertion failed.");']);
        throw new RuntimeException('Unable to replace forked QA child process.');
    }

    fclose($writer);
    try {
        $result = stream_get_contents($reader);
        pcntl_waitpid($pid, $status);
        expect(pcntl_wifexited($status))->toBeTrue()
            ->and(pcntl_wexitstatus($status))->toBe(0)
            ->and($result)->toBe('2')
            ->and($session->active())->toBeFalse();

        $session->dispose();
        expect($session->active())->toBeFalse()
            ->and($loop->diagnostics()->readWatchers)->toBe(0)
            ->and($loop->diagnostics()->writeWatchers)->toBe(0);
    } finally {
        fclose($reader);
        $session->dispose();
    }
});
