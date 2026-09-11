<?php

declare(strict_types=1);

use Infocyph\Runwire\Runtime\RuntimeEnvironment;
use Infocyph\Runwire\RuntimeDriver;

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

it('requires the complete unix process baseline for native eligibility', function (): void {
    $environment = new RuntimeEnvironment(
        sapi: 'cli',
        availableDrivers: [RuntimeDriver::NATIVE],
        supportsFork: true,
        supportsSignals: true,
        supportsPosix: true,
    );

    expect($environment->nativeEligible())->toBeTrue();
});

it('rejects auto as an environment capability', function (): void {
    new RuntimeEnvironment(sapi: 'cli', availableDrivers: [RuntimeDriver::AUTO]);
})->throws(InvalidArgumentException::class);
