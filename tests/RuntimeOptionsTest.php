<?php

declare(strict_types=1);

use Infocyph\Runwire\Runtime\Enum\OpcacheMode;
use Infocyph\Runwire\Runtime\Enum\RuntimeDriver;
use Infocyph\Runwire\RuntimeOptions;

it('defaults to automatic runtime and opcache selection with worker recycling and request deadlines disabled', function (): void {
    $options = new RuntimeOptions();

    expect($options->driver)->toBe(RuntimeDriver::AUTO)
        ->and($options->opcache)->toBe(OpcacheMode::AUTO)
        ->and($options->workerRecycle->enabled())->toBeFalse()
        ->and($options->requestExecution->maxExecutionSeconds)->toBeNull()
        ->and($options->fpm->maxRequestBodyBytes)->toBe(16_777_216)
        ->and($options->frankenPhp->maxResponseBytes)->toBe(16_777_216);
});

it('rejects forged worker-recycling jitter options that bypass their constructor guard', function (): void {
    $reflection = new ReflectionClass(\Infocyph\Runwire\Supervisor\WorkerRecyclePolicy::class);
    $invalid = $reflection->newInstanceWithoutConstructor();
    (new ReflectionProperty(\Infocyph\Runwire\Supervisor\WorkerRecyclePolicy::class, 'maxRequests'))
        ->setValue($invalid, 0);
    (new ReflectionProperty(\Infocyph\Runwire\Supervisor\WorkerRecyclePolicy::class, 'jitterRequests'))
        ->setValue($invalid, 1);

    expect(fn(): RuntimeOptions => new RuntimeOptions(workerRecycle: $invalid))
        ->toThrow(InvalidArgumentException::class, 'matching limit');
});
