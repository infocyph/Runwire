<?php

declare(strict_types=1);

use Infocyph\Runwire\Runtime\Enum\RuntimeDriver;
use Infocyph\Runwire\Runtime\RuntimeCapabilityResolver;
use Infocyph\Runwire\Runtime\RuntimeEnvironment;

it('reports full native capabilities when prefork support is wired', function (): void {
    $environment = new RuntimeEnvironment(
        sapi: 'cli', availableDrivers: [RuntimeDriver::NATIVE], supportsFork: true, supportsSignals: true,
        supportsPosix: true, supportsOpenSsl: true, opcacheAvailable: true, opcacheCliEnabled: true,
        supportsPrivilegeDrop: true,
    );
    $capabilities = (new RuntimeCapabilityResolver())->resolve(RuntimeDriver::NATIVE, $environment);
    expect($capabilities->ownsListener)->toBeTrue()
        ->and($capabilities->ownsEventLoop)->toBeTrue()
        ->and($capabilities->ownsWorkerPool)->toBeTrue()
        ->and($capabilities->supportsFork)->toBeTrue()
        ->and($capabilities->supportsAsyncIo)->toBeTrue()
        ->and($capabilities->supportsGracefulReload)->toBeTrue()
        ->and($capabilities->supportsWorkerRecycle)->toBeTrue()
        ->and($capabilities->supportsTlsAlpn)->toBeTrue()
        ->and($capabilities->supportsPrivilegeDrop)->toBeTrue()
        ->and($capabilities->supportsHttp1)->toBeTrue()
        ->and($capabilities->ownsHttp1Wire)->toBeTrue()
        ->and($capabilities->supportsHttp2)->toBeTrue()
        ->and($capabilities->ownsHttp2Wire)->toBeTrue();
});

it('keeps native listener and event-loop capabilities in single-process fallback mode', function (): void {
    $environment = new RuntimeEnvironment(
        sapi: 'cli',
        availableDrivers: [RuntimeDriver::NATIVE],
        supportsOpenSsl: true,
        supportsPosix: true,
        supportsPrivilegeDrop: true,
    );
    $capabilities = (new RuntimeCapabilityResolver())->resolve(RuntimeDriver::NATIVE, $environment);

    expect($capabilities->ownsListener)->toBeTrue()
        ->and($capabilities->ownsEventLoop)->toBeTrue()
        ->and($capabilities->ownsWorkerPool)->toBeFalse()
        ->and($capabilities->supportsFork)->toBeFalse()
        ->and($capabilities->supportsGracefulReload)->toBeFalse()
        ->and($capabilities->supportsWorkerRecycle)->toBeFalse()
        ->and($capabilities->supportsPrivilegeDrop)->toBeFalse()
        ->and($capabilities->supportsAsyncIo)->toBeTrue()
        ->and($capabilities->supportsRunwireCoroutines)->toBeTrue()
        ->and($capabilities->supportsHttp1)->toBeTrue()
        ->and($capabilities->supportsHttp2)->toBeTrue()
        ->and($capabilities->supportsTlsAlpn)->toBeTrue();
});

it('keeps fpm request-bound application state non-persistent', function (): void {
    $environment = new RuntimeEnvironment(sapi: 'fpm-fcgi', hostedDrivers: [RuntimeDriver::FPM]);
    $capabilities = (new RuntimeCapabilityResolver())->resolve(RuntimeDriver::FPM, $environment);
    expect($capabilities->persistentProcess)->toBeTrue()
        ->and($capabilities->persistentApplication)->toBeFalse()
        ->and($capabilities->ownsListener)->toBeFalse()
        ->and($capabilities->ownsWorkerPool)->toBeFalse();
});
