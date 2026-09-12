<?php

declare(strict_types=1);

use Infocyph\Runwire\Exception\RuntimeUnavailableException;
use Infocyph\Runwire\Runtime\Enum\RuntimeDriver;
use Infocyph\Runwire\Runtime\RuntimeDriverResolver;
use Infocyph\Runwire\Runtime\RuntimeEnvironment;
use Infocyph\Runwire\RuntimeOptions;

it('resolves hosted runtimes in deterministic precedence order', function (): void {
    $environment = new RuntimeEnvironment(
        sapi: 'custom',
        hostedDrivers: [
            RuntimeDriver::FPM,
            RuntimeDriver::ROADRUNNER,
            RuntimeDriver::SWOOLE,
            RuntimeDriver::FRANKENPHP,
        ],
    );

    expect((new RuntimeDriverResolver())->resolve(new RuntimeOptions(), $environment))
        ->toBe(RuntimeDriver::FRANKENPHP);
});

it('does not auto-select an installed but inactive swoole runtime', function (): void {
    $environment = new RuntimeEnvironment(
        sapi: 'cli',
        availableDrivers: [RuntimeDriver::SWOOLE, RuntimeDriver::NATIVE],
        supportsFork: true,
        supportsSignals: true,
        supportsPosix: true,
    );

    expect((new RuntimeDriverResolver())->resolve(new RuntimeOptions(), $environment))
        ->toBe(RuntimeDriver::NATIVE);
});

it('honors an explicitly selected available swoole runtime', function (): void {
    $environment = new RuntimeEnvironment(
        sapi: 'cli',
        availableDrivers: [RuntimeDriver::SWOOLE],
    );

    $options = new RuntimeOptions(driver: RuntimeDriver::SWOOLE);

    expect((new RuntimeDriverResolver())->resolve($options, $environment))
        ->toBe(RuntimeDriver::SWOOLE);
});

it('fails fast when an explicit host runtime is not active', function (): void {
    $environment = new RuntimeEnvironment(sapi: 'cli');
    $options = new RuntimeOptions(driver: RuntimeDriver::ROADRUNNER);

    (new RuntimeDriverResolver())->resolve($options, $environment);
})->throws(RuntimeUnavailableException::class, 'not the active host');
