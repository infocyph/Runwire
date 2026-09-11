<?php

declare(strict_types=1);

use Infocyph\Runwire\OpcacheMode;
use Infocyph\Runwire\RuntimeDriver;
use Infocyph\Runwire\RuntimeOptions;

it('defaults to automatic runtime and opcache selection', function (): void {
    $options = new RuntimeOptions();

    expect($options->driver)->toBe(RuntimeDriver::AUTO)
        ->and($options->opcache)->toBe(OpcacheMode::AUTO);
});
