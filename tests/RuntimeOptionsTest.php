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
        ->and($options->requestExecution->maxExecutionSeconds)->toBeNull();
});
