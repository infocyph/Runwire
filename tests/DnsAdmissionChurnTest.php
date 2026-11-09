<?php

declare(strict_types=1);

use Infocyph\Runwire\Executor\ProcessExecutor;
use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Network\AsyncDnsResolver;

it('bounds off-loop DNS admission and cancellation even under repeated resolver capacity exhaustion', function (): void {
    $loop = new SelectLoop();
    $executor = new ProcessExecutor($loop, 1, 2);
    $resolver = new AsyncDnsResolver($executor);
    $seen = [];
    $beforeMemory = memory_get_usage(true);
    $beforeFds = null;
    if (is_dir('/proc/self/fd')) {
        $entries = scandir('/proc/self/fd');
        if (!is_array($entries)) {
            throw new RuntimeException('Unable to inspect process file descriptors.');
        }
        $beforeFds = count($entries);
    }

    try {
        for ($wave = 0; $wave < 6; ++$wave) {
            $pending = [];
            for ($j = 0; $j < 3; ++$j) {
                $pending[] = $resolver->resolve('localhost', static function (array $addresses, ?Throwable $error) use (&$seen): void {
                    $seen[] = [$addresses, $error];
                });
            }
            expect(fn() => $resolver->resolve('localhost', static function (): void {}))
                ->toThrow(OverflowException::class);
            foreach ($pending as $id) {
                expect($resolver->cancel($id))->toBeTrue()
                    ->and($resolver->cancel($id))->toBeFalse();
            }
        }
        expect($seen)->toHaveCount(18)
            ->and($executor->diagnostics()['cancelled'])->toBe(18);
        foreach ($seen as [$addresses, $error]) {
            \PHPUnit\Framework\Assert::assertInstanceOf(Throwable::class, $error);
            expect($addresses)->toBe([]);
        }
    } finally {
        $executor->close();
    }

    expect($executor->diagnostics()['active'])->toBe(0)
        ->and($executor->diagnostics()['workers'])->toBe(0)
        ->and($loop->diagnostics()->readWatchers)->toBe(0)
        ->and($loop->diagnostics()->writeWatchers)->toBe(0)
        ->and($loop->diagnostics()->timersActive)->toBe(0)
        ->and(memory_get_usage(true) - $beforeMemory)->toBeLessThanOrEqual(33_554_432);

    if ($beforeFds !== null) {
        $entries = scandir('/proc/self/fd');
        if (!is_array($entries)) {
            throw new RuntimeException('Unable to inspect final file descriptors.');
        }
        expect(count($entries))->toBeLessThanOrEqual($beforeFds + 6);
    }
});
