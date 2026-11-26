<?php

declare(strict_types=1);

use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Network\BoundedConnectionPool;
use Infocyph\Runwire\Network\DialEndpoint;
use Infocyph\Runwire\Network\PoolIsolationKey;
use Infocyph\Runwire\Supervisor\WorkerContext;

it('retires owned pool waiters on stop without duplicate completion', function (): void {
    $loop = new SelectLoop();
    $context = new WorkerContext('owned-network', 0, 1, posix_getpid(), posix_getpid());
    $pool = new BoundedConnectionPool($loop, 1, 2);
    $key = new PoolIsolationKey(DialEndpoint::parse('tcp://127.0.0.1:9001'), 'worker');
    $closed = [];

    try {
        $context->ownPool($pool);
        $id = $pool->acquire($key, static function (): void {});
        $pool->acquire($key, static function (int $waiterId, mixed $connection, ?Throwable $error) use (&$closed): void {
            expect($connection)->toBeNull();
            $closed[] = [$waiterId, $error];
        });
        expect($pool->waitingCount())->toBe(1);

        $context->requestStop();
        $context->requestStop();
        $context->close();
        expect($closed)->toHaveCount(1)
            ->and($closed[0][1])->toBeInstanceOf(RuntimeException::class)
            ->and($pool->waitingCount())->toBe(0)
            ->and($pool->release($id))->toBeTrue()
            ->and($loop->diagnostics()->timersActive)->toBe(0)
            ->and($loop->diagnostics()->readWatchers)->toBe(0)
            ->and($loop->diagnostics()->writeWatchers)->toBe(0);
        expect(static fn() => $context->ownPool($pool))->toThrow(LogicException::class);
    } finally {
        $context->close();
        $pool->close();
    }
});
