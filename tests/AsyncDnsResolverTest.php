<?php

declare(strict_types=1);

use Infocyph\Runwire\CancellationSource;
use Infocyph\Runwire\Executor\ProcessExecutor;
use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Network\AsyncDnsResolver;
use Infocyph\Runwire\Runtime\Enum\CancellationReason;

it('resolves localhost off-loop after a busy worker without stalling the timer heartbeat', function (): void {
    $loop = new SelectLoop();
    $executor = new ProcessExecutor($loop, 1, 4);
    $resolver = new AsyncDnsResolver($executor);
    $ticks = 0;
    $addresses = [];
    $error = null;
    $timer = $loop->repeat(0.003, function () use (&$ticks): void {
        ++$ticks;
    });
    $executor->submit('sleep', ['milliseconds' => 60], static function (): void {});
    $resolver->resolve('localhost', function (array $result, ?Throwable $failure) use (&$addresses, &$error): void {
        $addresses = $result;
        $error = $failure;
    });
    $executor->onDrained(function () use ($loop, $executor, $timer): void {
        $loop->cancel($timer);
        $executor->close();
    });
    $loop->run();

    expect($error)->toBeNull()
        ->and($addresses)->toContain('127.0.0.1')
        ->and($ticks)->toBeGreaterThan(4)
        ->and($executor->diagnostics()['queued'])->toBe(0)
        ->and($executor->diagnostics()['workers'])->toBe(0)
        ->and($loop->diagnostics()->readWatchers)->toBe(0)
        ->and($loop->diagnostics()->writeWatchers)->toBe(0);
});

it('validates DNS names and settles cancellations only once', function (): void {
    $loop = new SelectLoop();
    $executor = new ProcessExecutor($loop, 1, 2);
    $resolver = new AsyncDnsResolver($executor);
    expect(fn() => $resolver->resolve('127.0.0.1', static function (): void {}))
        ->toThrow(InvalidArgumentException::class);
    $source = new CancellationSource();
    $count = 0;
    $executor->submit('sleep', ['milliseconds' => 60], static function (): void {});
    $id = $resolver->resolve('localhost', function (array $addresses, ?Throwable $error) use (&$count): void {
        expect($addresses)->toBe([])
            ->and($error)->toBeInstanceOf(Throwable::class);
        ++$count;
    }, $source->token());
    $source->cancel(CancellationReason::HOST_CANCELLED);
    expect($resolver->cancel($id))->toBeFalse();
    $executor->onDrained(function () use ($executor): void {
        $executor->close();
    });
    $loop->run();
    expect($count)->toBe(1)
        ->and($source->token()->subscriptionCount())->toBe(0)
        ->and($loop->diagnostics()->timersActive)->toBe(0);
    $source->dispose();
});
