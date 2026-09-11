<?php

declare(strict_types=1);

use Infocyph\Runwire\Exception\RuntimeUnavailableException;
use Infocyph\Runwire\OpcacheMode;
use Infocyph\Runwire\Runtime\RuntimeEnvironment;
use Infocyph\Runwire\Runtime\RuntimeSelector;
use Infocyph\Runwire\RuntimeDriver;
use Infocyph\Runwire\RuntimeOptions;

it('returns a resolved driver and capability snapshot', function (): void {
    $environment = new RuntimeEnvironment(
        sapi: 'cli',
        availableDrivers: [RuntimeDriver::NATIVE],
        supportsFork: true,
        supportsSignals: true,
        supportsPosix: true,
    );

    $selection = (new RuntimeSelector())->select(new RuntimeOptions(), $environment);

    expect($selection->driver)->toBe(RuntimeDriver::NATIVE)
        ->and($selection->capabilities->driver)->toBe(RuntimeDriver::NATIVE)
        ->and($selection->warnings)->toBe([]);
});

it('fails closed when opcache is required but disabled', function (): void {
    $environment = new RuntimeEnvironment(
        sapi: 'cli',
        availableDrivers: [RuntimeDriver::NATIVE],
        supportsFork: true,
        supportsSignals: true,
        supportsPosix: true,
        opcacheAvailable: true,
        opcacheEnabled: false,
    );

    $options = new RuntimeOptions(opcache: OpcacheMode::REQUIRED);

    (new RuntimeSelector())->select($options, $environment);
})->throws(RuntimeUnavailableException::class, 'OPcache is required');

it('reports a warning when opcache is requested but cannot be enabled', function (): void {
    $environment = new RuntimeEnvironment(
        sapi: 'cli',
        availableDrivers: [RuntimeDriver::NATIVE],
        supportsFork: true,
        supportsSignals: true,
        supportsPosix: true,
    );

    $selection = (new RuntimeSelector())->select(
        new RuntimeOptions(opcache: OpcacheMode::ON),
        $environment,
    );

    expect($selection->warnings)->toHaveCount(1);
});
