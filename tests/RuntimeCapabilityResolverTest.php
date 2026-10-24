<?php

declare(strict_types=1);

use Infocyph\Runwire\FrankenPhpOptions;
use Infocyph\Runwire\Runtime\Enum\FrankenPhpMode;
use Infocyph\Runwire\Runtime\Enum\RuntimeCapability;
use Infocyph\Runwire\Runtime\Enum\RuntimeDriver;
use Infocyph\Runwire\Runtime\RuntimeCapabilityResolver;
use Infocyph\Runwire\Runtime\RuntimeEnvironment;
use Infocyph\Runwire\RuntimeOptions;
use Infocyph\Runwire\SwooleOptions;

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
        ->and($capabilities->ownsHttp2Wire)->toBeTrue()
        ->and($capabilities->supportsWebsocket)->toBeTrue()
        ->and($capabilities->supports(RuntimeCapability::SUPPORTS_WEBSOCKET))->toBeTrue();
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
        ->and($capabilities->supportsWebsocket)->toBeTrue()
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


it('reports only enabled host protocol and lifecycle capabilities', function (): void {
    $environment = new RuntimeEnvironment(
        sapi: 'cli',
        availableDrivers: [
            RuntimeDriver::FRANKENPHP,
            RuntimeDriver::ROADRUNNER,
            RuntimeDriver::SWOOLE,
        ],
    );
    $resolver = new RuntimeCapabilityResolver();

    $frankenPhp = $resolver->resolve(
        RuntimeDriver::FRANKENPHP,
        $environment,
        new RuntimeOptions(frankenPhp: new FrankenPhpOptions(mode: FrankenPhpMode::WORKER)),
    );
    $roadRunner = $resolver->resolve(RuntimeDriver::ROADRUNNER, $environment);
    $swooleHttp1 = $resolver->resolve(RuntimeDriver::SWOOLE, $environment);
    $swooleHttp2 = $resolver->resolve(
        RuntimeDriver::SWOOLE,
        $environment,
        new RuntimeOptions(swoole: new SwooleOptions(http2: true)),
    );

    foreach ([$frankenPhp, $roadRunner] as $capabilities) {
        expect($capabilities->supportsHttp1)->toBeTrue()
            ->and($capabilities->supportsHttp2)->toBeFalse()
            ->and($capabilities->supportsHttp3)->toBeFalse()
            ->and($capabilities->supportsTlsAlpn)->toBeFalse()
            ->and($capabilities->supportsQuic)->toBeFalse()
            ->and($capabilities->supportsWebsocket)->toBeFalse()
            ->and($capabilities->supportsGracefulReload)->toBeFalse();
    }

    expect($frankenPhp->supportsWorkerRecycle)->toBeTrue()
        ->and($roadRunner->supportsWorkerRecycle)->toBeTrue()
        ->and($swooleHttp1->supportsHttp1)->toBeTrue()
        ->and($swooleHttp1->supportsHttp2)->toBeFalse()
        ->and($swooleHttp1->supportsHttp3)->toBeFalse()
        ->and($swooleHttp1->supportsGracefulReload)->toBeFalse()
        ->and($swooleHttp2->supportsHttp2)->toBeTrue();
});
