<?php

declare(strict_types=1);

use Infocyph\Runwire\Executor\ProcessExecutor;
use Infocyph\Runwire\Loop\SelectLoop;

it('rejects inherited executor use and close in a fork without disrupting parent-owned jobs', function (): void {
    expect(function_exists('pcntl_fork'))->toBeTrue();

    $loop = new SelectLoop();
    $executor = new ProcessExecutor($loop, 1, 2);
    [$reader, $writer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    $pid = pcntl_fork();
    expect($pid)->not->toBe(-1);

    if ($pid === 0) {
        fclose($reader);
        $rejections = 0;
        try {
            $executor->submit('sleep', ['milliseconds' => 1], static function (): void {});
        } catch (LogicException) {
            ++$rejections;
        }
        try {
            $executor->cancel(123);
        } catch (LogicException) {
            ++$rejections;
        }
        try {
            $executor->close();
        } catch (LogicException) {
            ++$rejections;
        }
        fwrite($writer, (string) $rejections);
        fclose($writer);
        exit($rejections === 3 ? 0 : 1);
    }

    fclose($writer);
    try {
        $childOutput = stream_get_contents($reader);
        $waited = pcntl_waitpid($pid, $status);
        expect($waited)->toBe($pid)
            ->and(pcntl_wifexited($status))->toBeTrue()
            ->and(pcntl_wexitstatus($status))->toBe(0)
            ->and($childOutput)->toBe('3');

        $observed = [];
        $executor->submit('sleep', ['milliseconds' => 1], static function (mixed $value, ?Throwable $error) use (&$observed): void {
            $observed[] = $error;
        });
        $executor->onDrained(static function () use ($executor): void {
            $executor->close();
        });
        $loop->run();

        expect($observed)->toBe([null])
            ->and($executor->diagnostics()['workers'])->toBe(0)
            ->and($loop->diagnostics()->readWatchers)->toBe(0)
            ->and($loop->diagnostics()->writeWatchers)->toBe(0);
    } finally {
        fclose($reader);
        $executor->close();
    }
});
