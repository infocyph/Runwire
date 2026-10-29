<?php

declare(strict_types=1);

use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Network\BoundedConnectionPool;
use Infocyph\Runwire\Network\DialEndpoint;
use Infocyph\Runwire\Network\IdleConnectionPool;
use Infocyph\Runwire\Network\PoolIsolationKey;

it('rejects cross-fork connection-pool leases and idle-socket access without disrupting the parent', function (): void {
    expect(function_exists('pcntl_fork'))->toBeTrue();

    $loop = new SelectLoop();
    $pool = new BoundedConnectionPool($loop, 1, 2);
    $idle = new IdleConnectionPool($loop, 1);
    $key = new PoolIsolationKey(DialEndpoint::parse('tcp://127.0.0.1:9001'), 'fork-scope');
    [$reader, $writer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    $pid = pcntl_fork();
    expect($pid)->not->toBe(-1);

    if ($pid === 0) {
        fclose($reader);
        $rejected = 0;
        foreach ([
            static fn() => $pool->acquire($key, static function (): void {}),
            static fn() => $pool->cancel(1),
            static fn() => $pool->release(1),
            static fn() => $pool->close(),
            static fn() => $pool->activeCount(),
            static fn() => $pool->idleCount(),
            static fn() => $pool->waitingCount(),
            static fn() => $pool->metrics(),
            static fn() => $idle->take($key),
            static fn() => $idle->close(),
            static fn() => $idle->count(),
        ] as $operation) {
            try {
                $operation();
            } catch (LogicException) {
                ++$rejected;
            }
        }
        fwrite($writer, (string) $rejected);
        fclose($writer);
        pcntl_exec(PHP_BINARY, ['-r', ($rejected === 11) ? '' : 'throw new RuntimeException("Child fork assertion failed.");']);
        throw new RuntimeException('Unable to replace forked QA child process.');
    }

    fclose($writer);
    try {
        $childOutput = stream_get_contents($reader);
        pcntl_waitpid($pid, $status);
        expect(pcntl_wifexited($status))->toBeTrue()
            ->and(pcntl_wexitstatus($status))->toBe(0)
            ->and($childOutput)->toBe('11');

        $observed = [];
        $id = $pool->acquire($key, static function (int $lease, mixed $connection, ?Throwable $error) use (&$observed): void {
            $observed[] = [$lease, $connection, $error];
        });
        expect($observed)->toHaveCount(1)
            ->and($observed[0][0])->toBe($id)
            ->and($observed[0][1])->toBeNull()
            ->and($observed[0][2])->toBeNull()
            ->and($pool->release($id))->toBeTrue();
        $pool->close();
        $idle->close();
        expect($loop->diagnostics()->timersActive)->toBe(0);
    } finally {
        fclose($reader);
        $pool->close();
        $idle->close();
    }
});
