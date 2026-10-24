<?php

declare(strict_types=1);

use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Network\BoundedConnectionPool;
use Infocyph\Runwire\Network\DialEndpoint;
use Infocyph\Runwire\Network\PoolIsolationKey;

it('stays within active and waiter quotas across repeated overloaded lease waves', function (): void {
    $loop = new SelectLoop();
    $pool = new BoundedConnectionPool($loop, maxActive: 4, maxWaiting: 32, maxIdle: 4);
    $key = new PoolIsolationKey(DialEndpoint::parse('unix:///tmp/runwire-pool-admission.sock'), 'load-worker');
    $beforeMemory = memory_get_usage(true);
    $beforeFds = is_dir('/proc/self/fd') ? count(scandir('/proc/self/fd')) : null;
    $completed = 0;
    $cancelled = 0;

    try {
        for ($wave = 0; $wave < 8; ++$wave) {
            $active = [];
            $granted = [];
            $waiters = [];
            $settled = [];
            for ($i = 0; $i < 4; ++$i) {
                $active[] = $pool->acquire($key, static function (): void {});
            }
            for ($i = 0; $i < 32; ++$i) {
                $waiters[] = $pool->acquire(
                    $key,
                    static function (int $id, $connection, ?Throwable $error) use (
                        &$settled,
                        &$granted,
                        &$cancelled,
                    ): void {
                        expect(isset($settled[$id]))->toBeFalse();
                        $settled[$id] = true;
                        if ($error !== null) {
                            ++$cancelled;

                            return;
                        }
                        expect($connection)->toBeNull();
                        $granted[] = $id;
                    },
                );
            }
            expect($pool->activeCount())->toBe(4)
                ->and($pool->waitingCount())->toBe(32)
                ->and(fn() => $pool->acquire($key, static function (): void {}))
                ->toThrow(OverflowException::class);
            foreach ($waiters as $index => $ticket) {
                if ($index % 2 === 0) {
                    expect($pool->cancel($ticket))->toBeTrue();
                }
            }
            foreach ($active as $ticket) {
                expect($pool->release($ticket))->toBeTrue();
            }
            while ($granted !== []) {
                $ticket = array_shift($granted);
                expect($pool->release($ticket))->toBeTrue();
                ++$completed;
            }
            expect($pool->activeCount())->toBe(0)
                ->and($pool->waitingCount())->toBe(0)
                ->and($settled)->toHaveCount(32);
        }
        expect($completed)->toBe(128)
            ->and($cancelled)->toBe(128)
            ->and(memory_get_usage(true) - $beforeMemory)->toBeLessThan(8_388_608);
        if ($beforeFds !== null) {
            expect(count(scandir('/proc/self/fd')) - $beforeFds)->toBeLessThanOrEqual(2);
        }
    } finally {
        $pool->close();
    }
    expect($loop->diagnostics()->readWatchers)->toBe(0)
        ->and($loop->diagnostics()->writeWatchers)->toBe(0)
        ->and($loop->diagnostics()->timersActive)->toBe(0);
});
