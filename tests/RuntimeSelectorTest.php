<?php

declare(strict_types=1);

use Infocyph\Runwire\Exception\RuntimeUnavailableException;
use Infocyph\Runwire\Runtime\Enum\OpcacheMode;
use Infocyph\Runwire\Runtime\Enum\RuntimeDriver;
use Infocyph\Runwire\Runtime\RuntimeEnvironment;
use Infocyph\Runwire\Runtime\RuntimeSelector;
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
        ->and($selection->capabilities->driver)->toBe(RuntimeDriver::NATIVE);

    if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
        expect($selection->warnings)->toContain(
            'SECURITY: native prefork is running as root without a worker privilege-drop policy; application workers will execute as root.',
        );
    } else {
        expect($selection->warnings)->toBe([]);
    }
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

    expect($selection->warnings)->toContain('OPcache was requested but is unavailable or disabled for the active PHP SAPI.');
});
