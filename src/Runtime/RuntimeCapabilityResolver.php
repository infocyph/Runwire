<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Runtime;

use Infocyph\Runwire\Runtime\Enum\FrankenPhpMode;
use Infocyph\Runwire\Runtime\Enum\RuntimeDriver;
use Infocyph\Runwire\RuntimeCapabilities;
use Infocyph\Runwire\RuntimeOptions;

/**
 * Builds concrete runtime capability sets from driver and environment information.
 */
final class RuntimeCapabilityResolver
{
    /**
     * Resolves capabilities for the selected runtime driver.
     */
    public function resolve(
        RuntimeDriver $driver,
        RuntimeEnvironment $environment,
        ?RuntimeOptions $options = null,
    ): RuntimeCapabilities {
        $options ??= new RuntimeOptions();

        return match ($driver) {
            RuntimeDriver::NATIVE => $this->native($environment),
            RuntimeDriver::FPM => $this->fpm($environment),
            RuntimeDriver::FRANKENPHP => $this->frankenPhp($environment, $options),
            RuntimeDriver::SWOOLE => $this->swoole($environment, $options),
            RuntimeDriver::ROADRUNNER => $this->roadRunner($environment),
            RuntimeDriver::AUTO => throw new \LogicException('AUTO must be resolved before capabilities are built.'),
        };
    }

    private function fpm(RuntimeEnvironment $environment): RuntimeCapabilities
    {
        return new RuntimeCapabilities(
            driver: RuntimeDriver::FPM,
            persistentProcess: true,
            persistentApplication: false,
            supportsHttp1: true,
            supportsOpcache: $environment->opcacheAvailable,
            supportsOpcacheCli: false,
            resources: $environment->resources,
        );
    }

    private function frankenPhp(RuntimeEnvironment $environment, RuntimeOptions $options): RuntimeCapabilities
    {
        $worker = $options->frankenPhp->mode === FrankenPhpMode::WORKER
            || ($options->frankenPhp->mode === FrankenPhpMode::AUTO && $environment->frankenPhpWorkerMode);

        return new RuntimeCapabilities(
            driver: RuntimeDriver::FRANKENPHP,
            persistentProcess: true,
            persistentApplication: $worker,
            hostOwnsEventLoop: true,
            supportsAsyncIo: true,
            supportsGracefulReload: true,
            supportsWorkerRecycle: $worker,
            supportsHttp1: true,
            supportsHttp2: true,
            supportsHttp3: true,
            supportsTlsAlpn: true,
            supportsQuic: true,
            supportsWebsocket: true,
            supportsOpcache: $environment->opcacheAvailable,
            supportsOpcacheCli: false,
            resources: $environment->resources,
        );
    }

    private function native(RuntimeEnvironment $environment): RuntimeCapabilities
    {
        return new RuntimeCapabilities(
            driver: RuntimeDriver::NATIVE,
            persistentProcess: true,
            persistentApplication: true,
            ownsListener: true,
            ownsEventLoop: true,
            ownsWorkerPool: true,
            runwireLoopAvailable: true,
            supportsFork: $environment->supportsFork,
            supportsSignals: $environment->supportsSignals,
            supportsAsyncIo: true,
            supportsRunwireCoroutines: true,
            supportsGracefulReload: $environment->supportsSignals,
            supportsWorkerRecycle: $environment->supportsSignals,
            supportsHttp1: true,
            supportsHttp2: true,
            supportsHttp3: $environment->supportsQuic,
            ownsHttp1Wire: true,
            ownsHttp2Wire: true,
            ownsHttp3Wire: $environment->supportsQuic,
            supportsTlsAlpn: $environment->supportsOpenSsl,
            supportsQuic: $environment->supportsQuic,
            supportsOpcache: $environment->opcacheAvailable,
            supportsOpcacheCli: $environment->opcacheCliEnabled,
            supportsReusePort: $environment->supportsReusePort,
            supportsUnixSockets: $environment->supportsUnixSockets,
            supportsPrivilegeDrop: $environment->supportsPrivilegeDrop,
            resources: $environment->resources,
        );
    }

    private function roadRunner(RuntimeEnvironment $environment): RuntimeCapabilities
    {
        return new RuntimeCapabilities(
            driver: RuntimeDriver::ROADRUNNER,
            persistentProcess: true,
            persistentApplication: true,
            hostOwnsEventLoop: true,
            supportsGracefulReload: true,
            supportsWorkerRecycle: true,
            supportsHttp1: true,
            supportsHttp2: true,
            supportsHttp3: true,
            supportsTlsAlpn: true,
            supportsQuic: true,
            supportsOpcache: $environment->opcacheAvailable,
            supportsOpcacheCli: $environment->opcacheCliEnabled,
            resources: $environment->resources,
        );
    }

    private function swoole(RuntimeEnvironment $environment, RuntimeOptions $options): RuntimeCapabilities
    {
        return new RuntimeCapabilities(
            driver: RuntimeDriver::SWOOLE,
            persistentProcess: true,
            persistentApplication: true,
            hostOwnsEventLoop: true,
            runwireLoopAvailable: true,
            supportsAsyncIo: true,
            supportsRunwireCoroutines: true,
            hostNativeCoroutines: true,
            supportsGracefulReload: true,
            supportsWorkerRecycle: true,
            supportsHttp1: true,
            supportsHttp2: $options->swoole->http2,
            supportsWebsocket: false,
            supportsOpcache: $environment->opcacheAvailable,
            supportsOpcacheCli: $environment->opcacheCliEnabled,
            resources: $environment->resources,
        );
    }
}
