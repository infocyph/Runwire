<?php

declare(strict_types=1);

use Infocyph\Runwire\Runtime\Enum\RuntimeDriver;
use Infocyph\Runwire\RuntimeCapabilities;

it('is immutable and defaults unsupported capabilities to false', function (): void {
    $capabilities = new RuntimeCapabilities(driver: RuntimeDriver::NATIVE);

    expect($capabilities->driver)->toBe(RuntimeDriver::NATIVE)
        ->and($capabilities->supportsFork)->toBeFalse()
        ->and($capabilities->supportsHttp2)->toBeFalse()
        ->and($capabilities->toArray()['driver'])->toBe('native');
});

it('reports explicitly enabled capabilities', function (): void {
    $capabilities = new RuntimeCapabilities(
        driver: RuntimeDriver::NATIVE,
        persistentProcess: true,
        ownsListener: true,
        ownsEventLoop: true,
        ownsWorkerPool: true,
        supportsFork: true,
        supportsSignals: true,
        supportsAsyncIo: true,
    );

    expect($capabilities->toArray())->toMatchArray([
        'persistent_process' => true,
        'owns_listener' => true,
        'owns_event_loop' => true,
        'owns_worker_pool' => true,
        'supports_fork' => true,
        'supports_signals' => true,
        'supports_async_io' => true,
    ]);
});
