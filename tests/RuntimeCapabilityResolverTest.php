<?php

declare(strict_types=1);

use Infocyph\Runwire\Runtime\RuntimeCapabilityResolver;
use Infocyph\Runwire\Runtime\RuntimeEnvironment;
use Infocyph\Runwire\RuntimeDriver;

it('reports only native capabilities whose implementation is wired', function (): void {
    $environment = new RuntimeEnvironment(
        sapi: 'cli',
        availableDrivers: [RuntimeDriver::NATIVE],
        supportsFork: true,
        supportsSignals: true,
        supportsPosix: true,
        supportsOpenSsl: true,
        opcacheAvailable: true,
        opcacheCliEnabled: true,
    );

    $capabilities = (new RuntimeCapabilityResolver())->resolve(RuntimeDriver::NATIVE, $environment);

    expect($capabilities->ownsListener)->toBeTrue()
        ->and($capabilities->ownsEventLoop)->toBeTrue()
        ->and($capabilities->ownsWorkerPool)->toBeTrue()
        ->and($capabilities->supportsFork)->toBeTrue()
        ->and($capabilities->supportsAsyncIo)->toBeTrue()
        ->and($capabilities->supportsGracefulReload)->toBeTrue()
        ->and($capabilities->supportsTlsAlpn)->toBeTrue()
        ->and($capabilities->supportsHttp1)->toBeTrue()
        ->and($capabilities->ownsHttp1Wire)->toBeTrue()
        ->and($capabilities->supportsHttp2)->toBeTrue()
        ->and($capabilities->ownsHttp2Wire)->toBeTrue();
});

it('keeps fpm request-bound application state non-persistent', function (): void {
    $environment = new RuntimeEnvironment(
        sapi: 'fpm-fcgi',
        hostedDrivers: [RuntimeDriver::FPM],
    );

    $capabilities = (new RuntimeCapabilityResolver())->resolve(RuntimeDriver::FPM, $environment);

    expect($capabilities->persistentProcess)->toBeTrue()
        ->and($capabilities->persistentApplication)->toBeFalse()
        ->and($capabilities->ownsListener)->toBeFalse()
        ->and($capabilities->ownsWorkerPool)->toBeFalse();
});
