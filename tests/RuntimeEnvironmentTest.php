<?php

declare(strict_types=1);

use Infocyph\Runwire\Runtime\Enum\RuntimeDriver;
use Infocyph\Runwire\Runtime\RuntimeEnvironment;

it('normalizes hosted drivers into the available set', function (): void {
    $environment = new RuntimeEnvironment(
        sapi: 'custom',
        hostedDrivers: [RuntimeDriver::ROADRUNNER, RuntimeDriver::ROADRUNNER],
        availableDrivers: [RuntimeDriver::SWOOLE],
    );

    expect($environment->hostedDrivers())->toBe([RuntimeDriver::ROADRUNNER])
        ->and($environment->availableDrivers())->toBe([
            RuntimeDriver::SWOOLE,
            RuntimeDriver::ROADRUNNER,
        ])
        ->and($environment->isHostedBy(RuntimeDriver::ROADRUNNER))->toBeTrue()
        ->and($environment->isAvailable(RuntimeDriver::SWOOLE))->toBeTrue();
});

it('keeps native CLI eligible without prefork process extensions', function (): void {
    $environment = new RuntimeEnvironment(
        sapi: 'cli',
        availableDrivers: [RuntimeDriver::NATIVE],
    );

    expect($environment->nativeEligible())->toBeTrue()
        ->and($environment->nativePreforkEligible())->toBeFalse();
});

it('requires the complete process baseline only for native prefork mode', function (): void {
    $environment = new RuntimeEnvironment(
        sapi: 'cli',
        availableDrivers: [RuntimeDriver::NATIVE],
        supportsFork: true,
        supportsSignals: true,
        supportsPosix: true,
    );

    expect($environment->nativeEligible())->toBeTrue()
        ->and($environment->nativePreforkEligible())->toBeTrue();
});

it('rejects auto as an environment capability', function (): void {
    new RuntimeEnvironment(sapi: 'cli', availableDrivers: [RuntimeDriver::AUTO]);
})->throws(InvalidArgumentException::class);
